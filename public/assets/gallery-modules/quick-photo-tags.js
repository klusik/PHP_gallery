/**
 * Project: PHP Gallery
 * Module Type: Browser Module
 * Purpose: Provide in-context, targeted direct-photo-tag controls to authorized editors.
 * Responsibilities:
 *   - Keep card and active-viewer tag state synchronized with narrow Admin mutations
 *   - Provide accessible suggestions, a remembered add choice and safe keyboard handling
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/quick-photo-tags.js
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

import {
    completeAdminMutation,
    normalizeAdminMutationEnvelope,
} from './admin-mutation-completion.js?v=20261021-smart-photo-tag-refresh-v1';
import {
    normalizeTagName as normalize,
    suggestionScore,
} from './tag-suggestions.js?v=20261021-shared-photo-tag-normalization-v1';

// Purpose: Keep the last server-confirmed photo-tag addition available while browsing this tab.
// Type: string.
// Units: one sessionStorage key.
// Scope: the current browser tab's sessionStorage namespace.
// Consumers: rememberedTag() reads it and rememberTag() writes it.
// Rationale: a tab-scoped preference survives gallery navigation and reloads without becoming a persistent account setting.
const QUICK_PHOTO_TAG_STORAGE_KEY = 'gallery.quickPhotoTag.lastAdded';
// Cap local search results to keep the anchored popup short while browsing.
// Type: integer. Units: tag options. Scope: one quick-tag popup.
// Consumers: renderOptions(). Rationale: four choices stay scannable beside the photo.
const QUICK_TAG_SUGGESTION_LIMIT = 4;
// Match the popup width used by its stylesheet before clamping to the viewport.
// Type: integer. Units: CSS pixels. Scope: quick-tag popup positioning.
// Consumers: positionPopover(). Rationale: 272px fits the compact input and controls.
const QUICK_TAG_POPOVER_MAX_WIDTH = 272;
const quickPhotoTagRoots = new WeakSet();

/**
 * Install one delegated quick-tag controller for the current page.
 * @param {Document|HTMLElement} root Stable page root for server-rendered controls.
 * @returns {void} Installs one controller when quick-tag markup exists.
 */
export function setupQuickPhotoTags(root = document) {
    if (!(root instanceof Document || root instanceof HTMLElement)
        || quickPhotoTagRoots.has(root)
        || !root.querySelector('[data-quick-photo-tag-popover]')) return;
    quickPhotoTagRoots.add(root);
    const popover = root.querySelector('[data-quick-photo-tag-popover]');
    const input = popover.querySelector('[data-quick-photo-tag-input]');
    const options = popover.querySelector('[data-quick-photo-tag-options]');
    const status = popover.querySelector('[data-quick-photo-tag-status]');
    const catalog = parseTagList(popover.dataset.quickPhotoTagCatalog);
    let active = null;
    let generation = 0;
    let highlighted = -1;
    let pending = false;
    /** @type {Promise<void>|null} */
    let escapeLockPending = null;
    /** @type {HTMLElement|null} */
    let escapeLockHeldViewer = null;
    /** @type {number} */
    let escapeLockFailedOwnerToken = 0;

    /**
     * Return the native fullscreen viewer that currently owns an open tag popup.
     * @returns {HTMLElement|null} Eligible native fullscreen viewer, when present.
     */
    function requestedEscapeLockViewer() {
        const viewer = active?.viewer || null;
        return !popover.hidden && viewer && document.fullscreenElement === viewer
            ? viewer : null;
    }

    /**
     * Reconcile the optional browser-level Escape lock with current popup ownership.
     * @returns {void} Starts acquisition or releases a settled lock as needed.
     */
    function reconcileEscapeLock() {
        const keyboard = navigator.keyboard;
        const viewer = requestedEscapeLockViewer();
        if (!viewer) {
            escapeLockFailedOwnerToken = 0;
            if (!escapeLockPending && escapeLockHeldViewer) {
                keyboard?.unlock?.();
                escapeLockHeldViewer = null;
            }
            return;
        }
        const ownerToken = active?.token || 0;
        if (!keyboard || typeof keyboard.lock !== 'function'
            || escapeLockPending || escapeLockHeldViewer === viewer
            || escapeLockFailedOwnerToken === ownerToken) return;

        let request;
        try {
            request = Promise.resolve(keyboard.lock(['Escape']));
        } catch {
            escapeLockFailedOwnerToken = ownerToken;
            return;
        }
        escapeLockPending = request;
        request.then(() => {
            if (escapeLockPending !== request) return;
            escapeLockPending = null;
            const currentViewer = requestedEscapeLockViewer();
            if (currentViewer) {
                escapeLockHeldViewer = currentViewer;
                return;
            }
            keyboard.unlock?.();
            escapeLockHeldViewer = null;
            reconcileEscapeLock();
        }).catch(() => {
            if (escapeLockPending !== request) return;
            escapeLockPending = null;
            escapeLockFailedOwnerToken = ownerToken;
            if (active?.token !== ownerToken) reconcileEscapeLock();
        });
    }

    /**
     * Read the last successfully added tag for this browser tab.
     * @returns {{id:number,name:string,slug:string}|null} Remembered tag.
     */
    function rememberedTag() {
        try {
            const value = JSON.parse(sessionStorage.getItem(QUICK_PHOTO_TAG_STORAGE_KEY) || 'null');
            return value && Number(value.id) > 0 && typeof value.name === 'string'
                ? {id: Number(value.id), name: value.name, slug: String(value.slug || '')} : null;
        } catch { return null; }
    }

    /**
     * Persist only a confirmed successful addition for this browser tab.
     * @param {{id:number,name:string,slug?:string}} tag Added image tag.
     * @returns {void} Saves the normalized identity.
     */
    function rememberTag(tag) {
        try {
            sessionStorage.setItem(QUICK_PHOTO_TAG_STORAGE_KEY, JSON.stringify(tag));
        } catch {
            // Storage can be disabled without blocking photo tagging.
        }
    }

    /**
     * Resolve an eligible card or active viewer source card.
     * @param {HTMLElement} trigger Requested icon.
     * @returns {HTMLElement|null} Authorized image trigger.
     */
    function sourceTrigger(trigger) {
        if (trigger.matches('[data-quick-photo-tag-card]') && trigger.isConnected) return trigger;
        if (
            trigger.dataset.quickTagEnabled === '1'
            && Number(trigger.dataset.imageId) > 0
        ) {
            return trigger;
        }
        const viewer = trigger.closest('[data-lightbox]');
        const imageId = Number(viewer?.dataset.currentImageId || 0);
        if (imageId < 1) return null;
        return [...root.querySelectorAll('[data-quick-photo-tag-card]')]
            .find((candidate) => Number(candidate.dataset.imageId) === imageId) || null;
    }

    /**
     * Choose the tag control visible in the viewer's current display mode.
     * @param {HTMLElement} viewer Active lightbox root.
     * @returns {HTMLButtonElement|null} Toolbar or fullscreen HUD trigger.
     */
    function viewerTagTrigger(viewer) {
        const fullscreen = viewer.classList.contains('is-fullscreen')
            || viewer.classList.contains('is-mobile-fullscreen');
        const selector = fullscreen
            ? '[data-quick-photo-tag-viewer].lightbox-hud:not([hidden])'
            : '[data-quick-photo-tag-viewer]:not(.lightbox-hud):not([hidden])';
        const preferred = viewer.querySelector(selector);
        return preferred instanceof HTMLButtonElement ? preferred : null;
    }

    /**
     * Synchronize icon states from direct photo tags without showing a tag list.
     * @param {HTMLElement} source Authorized card trigger.
     * @param {Array<{id:number,name:string,slug?:string}>} tags Current direct tags.
     * @returns {void} Updates this photo's visible state only.
     */
    function renderTags(source, tags) {
        const imageId = Number(source.dataset.imageId || 0);
        const sourceGalleryId = Number(
            source.dataset.sourceGalleryId || source.dataset.galleryId || 0,
        );
        const serializedTags = JSON.stringify(tags);
        const label = quickText(
            tags.length ? 'quick_photo_tags.edit' : 'quick_photo_tags.add',
            tags.length ? 'Edit photo tags' : 'Add photo tags',
        );
        const controls = [...root.querySelectorAll('[data-quick-photo-tag-trigger]')]
            .filter((button) => Number(button.dataset.imageId) === imageId
                && Number(button.dataset.sourceGalleryId
                    || button.dataset.galleryId || 0) === sourceGalleryId);
        if (!controls.includes(source)) controls.push(source);
        controls.forEach((button) => {
            button.dataset.quickPhotoTags = serializedTags;
            button.classList.toggle('is-empty', tags.length === 0);
            button.classList.toggle('is-active', tags.length > 0);
            button.setAttribute('aria-pressed', tags.length ? 'true' : 'false');
            button.setAttribute(
                'aria-expanded',
                active?.imageId === imageId && active.galleryId === sourceGalleryId && !popover.hidden
                    ? 'true' : 'false',
            );
            button.setAttribute('aria-label', label);
            button.title = label;
        });
    }

    /**
     * Open the compact dialog for one source photo without changing page state.
     * @param {HTMLElement} trigger Authorized card or viewer button.
     * @returns {void} Binds the popup to stable image and source gallery identities.
     */
    function open(trigger) {
        const source = sourceTrigger(trigger);
        if (!source || source.dataset.quickTagEnabled !== '1') return;
        const viewer = trigger.closest('[data-lightbox]');
        if (viewer && (viewer.classList.contains('is-presentation-exclusive')
            || viewer.classList.contains('is-presentation-intro')
            || viewer.classList.contains('is-presentation-launch')
            || viewer.classList.contains('is-slideshow'))) return;
        active = {
            imageId: Number(source.dataset.imageId),
            galleryId: Number(source.dataset.sourceGalleryId || 0),
            source,
            viewer: viewer || null,
            trigger,
            token: ++generation,
            tags: parseTagList(source.dataset.quickPhotoTags),
        };
        const pageHost = root.querySelector('main.site-main') || document.body;
        const popoverHost = viewer || pageHost;
        if (popover.parentElement !== popoverHost) popoverHost.append(popover);
        popover.hidden = false;
        positionPopover(trigger);
        popover.dataset.activeImageId = String(active.imageId);
        popover.dataset.activeGalleryId = String(active.galleryId);
        renderTags(source, active.tags);
        trigger.setAttribute('aria-expanded', 'true');
        const preferred = rememberedTag();
        input.value = preferred && !active.tags.some((tag) => tag.id === preferred.id
            || normalize(tag.name) === normalize(preferred.name))
            ? preferred.name : '';
        renderOptions(false);
        status.textContent = '';
        reconcileEscapeLock();
        input.focus();
    }

    /**
     * Toggle the popover for the same photo while allowing another photo to rebind it.
     * @param {HTMLElement} trigger Activated card or viewer tag control.
     * @returns {void} Closes the current photo or opens the requested photo.
     */
    function toggleForTrigger(trigger) {
        const source = sourceTrigger(trigger);
        if (
            !popover.hidden
            && active
            && source
            && (
                active.trigger === trigger
                || (
                    active.imageId === Number(source.dataset.imageId)
                    && active.galleryId
                        === Number(source.dataset.sourceGalleryId || 0)
                )
            )
        ) {
            close();
            return;
        }
        open(trigger);
    }

    /**
     * Close the dialog and restore focus when possible.
     * @param {boolean} restoreFocus Whether to return focus to the invoking control.
     * @param {boolean} rearmHud Whether a non-focus dismissal should restart fullscreen auto-hide.
     * @returns {void} Clears pending photo ownership.
     */
    function close(restoreFocus = true, rearmHud = false) {
        const wasOpen = !popover.hidden;
        const trigger = active?.trigger;
        const viewer = trigger?.closest('[data-lightbox]');
        const restoreFullscreenHud = (
            wasOpen
            && (restoreFocus || rearmHud)
            && viewer
            && (viewer.classList.contains('is-fullscreen')
                || viewer.classList.contains('is-mobile-fullscreen'))
        );
        generation += 1;
        active = null;
        popover.hidden = true;
        options.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        root.querySelectorAll('[data-quick-photo-tag-trigger]').forEach((button) => {
            if (Number(button.dataset.imageId) === Number(trigger?.dataset.imageId || 0)) {
                button.setAttribute('aria-expanded', 'false');
            }
        });
        reconcileEscapeLock();
        if (restoreFullscreenHud) viewer.dispatchEvent(new Event('quickPhotoTagFocus'));
        if (wasOpen && restoreFocus && trigger?.isConnected) trigger.focus();
    }

    /**
     * Position the anchored popup within the current visual viewport.
     * @param {HTMLElement} trigger Invoking card or viewer icon.
     * @returns {void} Updates fixed popup coordinates without changing layout.
     */
    function positionPopover(trigger) {
        const rect = trigger.getBoundingClientRect();
        const viewport = window.visualViewport;
        const leftEdge = viewport?.offsetLeft || 0;
        const topEdge = viewport?.offsetTop || 0;
        const viewportWidth = viewport?.width || window.innerWidth;
        const viewportHeight = viewport?.height || window.innerHeight;
        const width = Math.min(QUICK_TAG_POPOVER_MAX_WIDTH, Math.max(0, viewportWidth - 16));
        const height = popover.offsetHeight;
        const left = Math.min(
            Math.max(leftEdge + 8, rect.left),
            leftEdge + viewportWidth - width - 8,
        );
        const below = rect.bottom + 8;
        const top = below + height <= topEdge + viewportHeight - 8
            ? below : Math.max(topEdge + 8, rect.top - height - 8);
        popover.style.left = `${left}px`;
        popover.style.top = `${top}px`;
    }

    /**
     * Render a short image-taxonomy suggestion list only after explicit typing.
     * @param {boolean} showSuggestions Whether typing should reveal the short suggestion list.
     * @returns {void} Rebuilds the local suggestion list.
     */
    function renderOptions(showSuggestions = true) {
        const term = normalize(input.value);
        const matching = !showSuggestions || term === '' ? [] : catalog
            .map((tag, index) => ({tag, index, score: suggestionScore(tag.name, term)}))
            .filter(({tag, score}) => score >= 0
                && !active?.tags.some((assigned) => assigned.id === tag.id))
            .sort((left, right) => left.score - right.score || left.index - right.index)
            .slice(0, QUICK_TAG_SUGGESTION_LIMIT).map(({tag}) => tag);
        highlighted = term !== '' && matching.length > 0 ? 0 : -1;
        options.replaceChildren(...matching.map((tag, index) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.setAttribute('role', 'option');
            option.id = `quick-photo-tag-option-${index}`;
            option.dataset.tagId = String(tag.id);
            option.dataset.tagName = tag.name;
            option.setAttribute('aria-selected', index === highlighted ? 'true' : 'false');
            option.textContent = tag.name;
            return option;
        }));
        options.hidden = matching.length === 0;
        input.setAttribute('aria-expanded', options.hidden ? 'false' : 'true');
        if (highlighted >= 0) {
            input.setAttribute(
                'aria-activedescendant',
                `quick-photo-tag-option-${highlighted}`,
            );
        }
        else input.removeAttribute('aria-activedescendant');
    }

    /**
     * Add one tag directly to the active photo and reconcile that photo generation.
     * @param {{id?:number,name:string,slug?:string}} tag Selected image tag.
     * @returns {Promise<void>} Reconciles authoritative state or reports an error.
     */
    async function addTag(tag) {
        if (!active || pending || !tag.name.trim()) return;
        const owner = active;
        pending = true;
        popover.setAttribute('aria-busy', 'true');
        const body = new FormData();
        body.set('action', 'quick_tag_add');
        body.set('image_id', String(owner.imageId));
        body.set('source_gallery_id', String(owner.galleryId));
        body.set('csrf_token', popover.dataset.quickTagCsrf || '');
        if (tag.id) body.set('tag_id', String(tag.id));
        else body.set('tag_name', tag.name.trim());
        try {
            let response;
            try {
                response = await fetch(popover.dataset.quickTagUrl, {
                    method: 'POST', body, credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
            } catch {
                if (active?.token === owner.token) {
                    status.textContent = quickText(
                        'admin.quick_tags.unconfirmed',
                        'The server did not confirm whether the tag was saved. Reopen or refresh the photo to verify.',
                    );
                }
                return;
            }

            let result;
            try {
                result = await response.json();
            } catch {
                if (active?.token === owner.token) {
                    status.textContent = quickText(
                        'admin.quick_tags.unconfirmed',
                        'The server did not confirm whether the tag was saved. Reopen or refresh the photo to verify.',
                    );
                }
                return;
            }

            const payload = result && typeof result === 'object' && !Array.isArray(result)
                ? result : {};
            if (!response.ok || payload.ok !== true) {
                const serverMessage = String(
                    payload.message || payload.error || '',
                ).trim();
                const reference = String(payload.reference || '').trim();
                const safeReference = /^[A-Za-z0-9_-]+$/.test(reference)
                    ? reference : '';
                const message = response.status >= 500
                    ? safeReference
                        ? quickText(
                            'admin.quick_tags.server_error',
                            'The server returned HTTP {status} (reference {reference}). Check the photo before trying again.',
                            {status: response.status, reference: safeReference},
                        )
                        : quickText(
                            'admin.quick_tags.unconfirmed',
                            'The server did not confirm whether the tag was saved. Reopen or refresh the photo to verify.',
                        )
                    : serverMessage || quickText(
                        'admin.quick_tags.request_failed',
                        'The photo-tag request was rejected (HTTP {status}).',
                        {status: response.status},
                    );
                if (active?.token === owner.token) status.textContent = message;
                return;
            }

            let normalizedPayload;
            try {
                normalizedPayload = normalizeAdminMutationEnvelope(payload);
            } catch {
                if (active?.token === owner.token) {
                    status.textContent = quickText(
                        'admin.quick_tags.unconfirmed',
                        'The server did not confirm whether the tag was saved. Reopen or refresh the photo to verify.',
                    );
                }
                return;
            }
            const mutation = normalizedPayload.mutation;
            const imageContext = normalizedPayload.contexts.find((context) => {
                const postcondition = context.postcondition;
                return context.type === 'gallery'
                    && context.gallery_id === owner.galleryId
                    && postcondition?.type === 'image_tags'
                    && Number(postcondition.image_id) === owner.imageId
                    && Array.isArray(postcondition.tag_ids)
                    && Array.isArray(postcondition.tag_names);
            });
            const postcondition = imageContext?.postcondition;
            const responseTags = Array.isArray(payload.direct_tags)
                ? payload.direct_tags : null;
            const authoritative = responseTags
                ? parseTagList(JSON.stringify(responseTags)) : [];
            const postconditionIds = Array.isArray(postcondition?.tag_ids)
                ? postcondition.tag_ids.map(Number).sort((left, right) => left - right)
                : [];
            const directTagIds = authoritative.map((tag) => tag.id).sort((left, right) => left - right);
            const postconditionNames = Array.isArray(postcondition?.tag_names)
                ? postcondition.tag_names.map((name) => String(name).trim()).sort()
                : [];
            const directTagNames = authoritative.map((tag) => tag.name).sort();
            const mutationMatchesOwner = mutation.type === 'image.tag'
                && mutation.entity === 'image'
                && mutation.action === 'add'
                && mutation.entity_ids.length === 1
                && mutation.entity_ids[0] === owner.imageId;
            const postconditionMatchesTags = postconditionIds.length === directTagIds.length
                && postconditionIds.every((id, index) => Number.isInteger(id)
                    && id > 0 && id === directTagIds[index])
                && postconditionNames.length === directTagNames.length
                && postconditionNames.every((name, index) => name === directTagNames[index]);
            if (!mutationMatchesOwner
                || !imageContext
                || !responseTags
                || authoritative.length !== responseTags.length
                || !postconditionMatchesTags) {
                if (active?.token === owner.token) {
                    status.textContent = quickText(
                        'admin.quick_tags.unconfirmed',
                        'The server did not confirm whether the tag was saved. Reopen or refresh the photo to verify.',
                    );
                }
                return;
            }
            const changed = payload.changed_tag;
            const changedIdentity = changed && authoritative.find((tag) => tag.id === Number(changed.id)
                && normalize(tag.name) === normalize(String(changed.name || '')));
            if (payload.already_present !== true && changedIdentity) {
                rememberTag({
                    id: changedIdentity.id,
                    name: changedIdentity.name,
                    slug: String(changed.slug || changedIdentity.slug || ''),
                });
            }

            if (!active || active.token !== owner.token) return;
            owner.tags = authoritative;
            const serializedAuthoritativeTags = JSON.stringify(authoritative);
            if (owner.viewer instanceof HTMLElement
                && Number(owner.viewer.dataset.currentImageId || 0) === owner.imageId
                && Number(owner.viewer.dataset.currentSourceGalleryId || 0) === owner.galleryId) {
                owner.viewer.dataset.currentQuickPhotoTags = serializedAuthoritativeTags;
            }
            renderTags(owner.source, authoritative);
            status.textContent = String(payload.message || quickText(
                payload.already_present === true
                    ? 'admin.quick_tags.already_present'
                    : 'admin.quick_tags.added',
                payload.already_present === true
                    ? 'This photo already has that tag.'
                    : 'Photo tag added.',
            ));
            input.value = '';
            renderOptions();
            const refreshed = sourceTrigger(owner.trigger);
            if (refreshed) {
                owner.source = refreshed;
                renderTags(refreshed, authoritative);
            }
            let syncResult = null;
            try {
                syncResult = await completeAdminMutation(payload);
            } catch {
                syncResult = null;
            }
            if (active?.token !== owner.token) return;
            if (!syncResult?.synchronized) {
                status.textContent = quickText(
                    'admin.quick_tags.saved_refresh',
                    'The tag was saved, but this photo view could not be refreshed. Reopen it to verify.',
                );
                return;
            }
            input.value = '';
            renderOptions();
            close();
        } catch (error) {
            if (active?.token === owner.token) {
                status.textContent = quickText(
                    'admin.quick_tags.saved_refresh',
                    'The tag was saved, but this photo view could not be refreshed. Reopen it to verify.',
                );
            }
        } finally {
            pending = false;
            popover.removeAttribute('aria-busy');
        }
    }

    /**
     * Submit selected suggestion, remembered tag, or entered taxonomy name.
     * @returns {void} Starts one targeted add if the photo lacks the tag.
     */
    function submit() {
        const name = String(input.value || '').trim();
        if (!name) {
            const tag = rememberedTag();
            if (tag && !active?.tags.some((item) => item.id === tag.id
                || normalize(item.name) === normalize(tag.name))) void addTag(tag);
            return;
        }
        const existing = catalog.find((tag) => normalize(tag.name) === normalize(name));
        void addTag(existing || {name});
    }

    root.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const trigger = target?.closest('[data-quick-photo-tag-trigger]');
        if (!popover.hidden && !popover.contains(target) && !trigger) close(false, true);
        if (trigger instanceof HTMLElement) {
            event.preventDefault(); event.stopPropagation();
            toggleForTrigger(trigger);
            return;
        }
        if (target?.closest('[data-quick-photo-tag-close]')) {
            event.preventDefault();
            event.stopPropagation();
            close();
            return;
        }
        const option = target?.closest('[role="option"]');
        if (option instanceof HTMLElement) {
            input.value = option.dataset.tagName || '';
            renderOptions();
            submit();
            return;
        }
        if (target?.closest('[data-quick-photo-tag-add]')) { submit(); return; }
    }, true);
    input.addEventListener('input', () => renderOptions());
    window.addEventListener('keydown', (event) => {
        if (!popover.hidden) {
            event.stopImmediatePropagation();
            if (event.key === 'Escape') { event.preventDefault(); close(); return; }
            if (event.isComposing || event.keyCode === 229) return;
            if (
                event.target === input
                && event.key === 'Tab'
                && !event.shiftKey
                && !options.hidden
            ) {
                const selected = options.querySelector('[aria-selected="true"]');
                if (selected) {
                    event.preventDefault();
                    input.value = selected.dataset.tagName || '';
                    renderOptions();
                }
            } else if (event.target === input && event.key === 'Enter') {
                event.preventDefault(); submit();
            } else if (
                event.target === input
                && (event.key === 'ArrowDown' || event.key === 'ArrowUp')
            ) {
                const items = [...options.querySelectorAll('[role="option"]')];
                if (!items.length) return;
                event.preventDefault();
                const direction = event.key === 'ArrowDown' ? 1 : items.length - 1;
                highlighted = (highlighted + direction) % items.length;
                items.forEach((item, index) => item.setAttribute(
                    'aria-selected',
                    index === highlighted ? 'true' : 'false',
                ));
                input.setAttribute('aria-activedescendant', items[highlighted].id);
            }
            return;
        }
        if (
            event.isComposing
            || event.keyCode === 229
            || event.ctrlKey
            || event.altKey
            || event.metaKey
        ) return;
        if (event.key.toLowerCase() !== 't' || event.repeat || isEditable(event.target)) return;
        const viewer = root.querySelector('[data-lightbox]:not([hidden])');
        if (viewer?.classList.contains('is-presentation-exclusive')
            || viewer?.classList.contains('is-presentation-intro')
            || viewer?.classList.contains('is-presentation-launch')
            || viewer?.classList.contains('is-slideshow')) return;
        const fullscreen = viewer?.classList.contains('is-fullscreen')
            || viewer?.classList.contains('is-mobile-fullscreen');
        const trigger = viewer ? viewerTagTrigger(viewer) : null;
        if (trigger instanceof HTMLElement && trigger.dataset.quickTagAvailable === '1') {
            if (fullscreen) viewer.dispatchEvent(new Event('quickPhotoTagFocus'));
            event.preventDefault(); event.stopImmediatePropagation(); open(trigger);
        }
    }, true);
    root.addEventListener('quickPhotoTagActiveImageChanged', (event) => {
        const viewer = event.target instanceof HTMLElement
            ? event.target.closest('[data-lightbox]') : null;
        const imageId = Number(event.detail?.imageId || 0);
        const sourceGalleryId = Number(event.detail?.sourceGalleryId || 0);
        if (!(viewer instanceof HTMLElement)
            || Number(viewer.dataset.currentImageId || 0) !== imageId
            || Number(viewer.dataset.currentSourceGalleryId || 0) !== sourceGalleryId) return;
        if (active && (active.imageId !== Number(event.detail?.imageId || 0)
            || active.galleryId !== Number(event.detail?.sourceGalleryId || 0)
            || event.detail?.slideshow || event.detail?.presentation)) close(false);
        const source = [...root.querySelectorAll('[data-quick-photo-tag-card]')]
            .find((candidate) => Number(candidate.dataset.imageId) === imageId
                && Number(candidate.dataset.sourceGalleryId
                    || candidate.dataset.galleryId || 0) === sourceGalleryId);
        const currentPhotoTags = typeof event.detail?.quickPhotoTags === 'string'
            ? JSON.stringify(parseTagList(event.detail.quickPhotoTags))
            : source?.dataset.quickPhotoTags || '[]';
        root.querySelectorAll('[data-quick-photo-tag-viewer]').forEach((button) => {
            if (!(button instanceof HTMLButtonElement)) return;
            const permitted = (
                source instanceof HTMLElement && source.dataset.quickTagEnabled === '1'
            )
                || event.detail?.quickTagEnabled === true;
            const available = permitted && !event.detail?.slideshow && !event.detail?.presentation;
            button.hidden = !available;
            button.dataset.quickTagAvailable = available ? '1' : '0';
            if (!available) return;
            button.dataset.imageId = String(imageId);
            button.dataset.sourceGalleryId = source?.dataset.sourceGalleryId
                || String(event.detail?.sourceGalleryId || '');
            button.dataset.quickPhotoTags = currentPhotoTags;
            button.dataset.quickTagEnabled = '1';
            const hasTags = parseTagList(button.dataset.quickPhotoTags).length > 0;
            button.classList.toggle('is-empty', !hasTags);
            button.classList.toggle('is-active', hasTags);
            button.setAttribute('aria-pressed', hasTags ? 'true' : 'false');
            const label = quickText(
                hasTags ? 'quick_photo_tags.edit' : 'quick_photo_tags.add',
                hasTags ? 'Add another photo tag' : 'Add photo tags',
            );
            button.setAttribute('aria-label', label);
            button.title = label;
            button.setAttribute(
                'aria-expanded',
                active?.imageId === imageId && !popover.hidden ? 'true' : 'false',
            );
        });
    });
    synchronizeOpenViewer();
    root.addEventListener('publicGalleryPhotoOrderChanged', () => {
        if (!active) return;
        const refreshed = [...root.querySelectorAll('[data-quick-photo-tag-card]')]
            .find((candidate) => Number(candidate.dataset.imageId) === active.imageId);
        if (refreshed) {
            active.source = refreshed;
            renderTags(refreshed, active.tags);
            return;
        }
        if (!active.viewer) close(false);
    });
    root.addEventListener('quickPhotoTagSlideshowChanged', (event) => {
        if (event.detail?.active && active) close(false);
        root.querySelectorAll('[data-quick-photo-tag-viewer]').forEach((button) => {
            if (event.detail?.active) button.hidden = true;
        });
    });
    document.addEventListener('fullscreenchange', () => {
        if (active?.viewer && document.fullscreenElement !== active.viewer
            && !popover.hidden) close(false);
        else reconcileEscapeLock();
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden && active?.viewer && !popover.hidden) close(false);
    });
    window.addEventListener('quickPhotoTagViewerClosed', () => {
        if (active?.viewer) close(false);
    });
    const reposition = () => {
        if (!popover.hidden && active?.trigger?.isConnected) positionPopover(active.trigger);
    };
    window.addEventListener('resize', reposition, {passive: true});
    window.visualViewport?.addEventListener('resize', reposition, {passive: true});
    window.visualViewport?.addEventListener('scroll', reposition, {passive: true});

    /**
     * Hydrate tag controls if the deferred module loads after the viewer opened.
     * @returns {void} Replays the current physical-image state from the lightbox root.
     */
    function synchronizeOpenViewer() {
        const viewer = root.querySelector('[data-lightbox]:not([hidden])');
        const imageId = Number(viewer?.dataset.currentImageId || 0);
        if (!(viewer instanceof HTMLElement) || imageId < 1) return;
        viewer.dispatchEvent(new CustomEvent('quickPhotoTagActiveImageChanged', {
            bubbles: true,
            detail: {
                imageId,
                sourceGalleryId: Number(viewer.dataset.currentSourceGalleryId || 0),
                quickTagEnabled: viewer.dataset.currentQuickTagEnabled === '1',
                quickPhotoTags: viewer.dataset.currentQuickPhotoTags || '[]',
                slideshow: viewer.classList.contains('is-slideshow'),
                presentation: viewer.classList.contains('is-presentation-exclusive')
                    || viewer.classList.contains('is-presentation-intro')
                    || viewer.classList.contains('is-presentation-launch'),
            },
        }));
    }
}

/**
 * Decode a compact serialized direct image-tag list.
 * @param {string|undefined} raw JSON or already stringified tag collection.
 * @returns {Array<{id:number,name:string,slug:string}>} Valid image-tag identities.
 */
function parseTagList(raw) {
    try {
        const values = typeof raw === 'string' ? JSON.parse(raw) : [];
        return Array.isArray(values)
            ? values.filter((tag) => tag && Number(tag.id) > 0 && String(tag.name || '').trim())
                .map((tag) => ({
                    id: Number(tag.id),
                    name: String(tag.name).trim(),
                    slug: String(tag.slug || ''),
                }))
            : [];
    } catch { return []; }
}

/**
 * Identify editable fields and controls where global viewer shortcuts do not apply.
 * @param {EventTarget|null} target Focused keyboard event target.
 * @returns {boolean} Whether the target accepts user text or native editing keys.
 */
function isEditable(target) {
    return target instanceof Element && Boolean(target.closest(
        'input,textarea,select,[contenteditable="true"],[role="textbox"]',
    ));
}

/**
 * Resolve a localized quick-tag string and interpolate its named values.
 * @param {string} key Locale catalog key emitted by PHP.
 * @param {string} fallback Readable English fallback.
 * @param {Record<string,string|number>} values Placeholder values.
 * @returns {string} Localized label with placeholders replaced.
 */
function quickText(key, fallback, values = {}) {
    const translations = window.PHP_GALLERY_I18N?.strings;
    let text = typeof translations?.[key] === 'string' ? translations[key] : fallback;
    Object.entries(values).forEach(([name, value]) => {
        text = text.split(`{${name}}`).join(String(value));
    });
    return text;
}
