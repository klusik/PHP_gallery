/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-gallery-music.js
 * Module Type: Browser Module
 * Purpose: Keep gallery playlist mutations inside their originating editor
 *     card.
 * Responsibilities: Delegate dynamic forms and preserve canonical completion.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {completeAdminMutation} from './admin-mutation-completion.js?v=20261021-smart-photo-tag-refresh-v1';
import {stopGalleryMusicViewer} from './gallery-music-player.js?v=20261024-catalog-workspace-v3';

/** Bound one lazy album read without holding an editor indefinitely.
 * Type: number. Units: milliseconds. Scope: one catalog album page request.
 * Consumers: loadMusicCatalogAlbum and addSelectedMusicCatalogItems.
 * Rationale: 20000 matches the existing editor resource deadline and allows
 * a failed read to expose an explicit retry rather than remain busy forever.
 */
const catalogReadTimeoutMs = 20000;

/** Bound one explicit multi-selection to the existing gallery source capacity.
 * Type: number. Units: selections. Scope: one Add selected operation.
 * Consumers: addSelectedMusicCatalogItems.
 * Rationale: 100 matches the physical playlist source cap and prevents an
 * unbounded sequence of authenticated writes from loaded album pages.
 */
const catalogBatchLimit = 100;
/** @type {Set<string>} Gallery identities with an active serial add operation. */
const musicCatalogBatchRuns = new Set();

/**
 * State and the latest desired value for one gallery's serialized preference writes.
 * @typedef {{desired: boolean, confirmed: boolean, running: boolean, synchronized: boolean}} MusicPreferenceSaveState
 */
/** @type {Map<string, MusicPreferenceSaveState>} Latest preference state per gallery. */
const musicPreferenceSaves = new Map();

/**
 * Bind music forms once, including forms rendered by later panel replacements.
 * @returns {void} Install capture delegation before generic Admin form
 *     handlers.
 */
export function setupGalleryMusic() {
    if (document.body.dataset.galleryMusicBound === '1') return;
    document.body.dataset.galleryMusicBound = '1';
    document.addEventListener('click', updateGalleryMediaSectionReturn, true);
    document.addEventListener('keyup', updateGalleryMediaSectionReturn, true);
    document.addEventListener('change', saveMusicPreferenceChange, true);
    document.addEventListener('submit', submitMusicCatalogSearch, true);
    document.addEventListener('submit', handleGalleryMusicMutationSubmit, true);
    document.addEventListener('toggle', loadMusicCatalogAlbum, true);
    document.addEventListener('click', loadMusicCatalogAlbum, true);
    document.addEventListener('change', updateMusicCatalogSelection, true);
    document.addEventListener('click', addSelectedMusicCatalogItems, true);
}

/** Reflect the number of selected, available catalog items.
 * @param {Event} event Checkbox selection change inside a catalog.
 * @returns {void} Update the owned count and Add selected availability.
 */
function updateMusicCatalogSelection(event) {
    if (!(event.target instanceof HTMLInputElement)
        || !event.target.matches('[data-music-catalog-select]')) return;
    const card = event.target.closest('[data-music-editor="1"]');
    if (!card) return;
    const count = new Set(Array.from(card.querySelectorAll(
        '[data-music-catalog-select]:checked:not(:disabled)',
    ), input => input.value)).size;
    const output = card.querySelector('[data-music-selection-count]');
    const button = card.querySelector('[data-music-add-selected]');
    if (output) output.textContent = count > 0
        ? output.dataset.label.replace('{count}', String(count)) : '';
    if (button instanceof HTMLButtonElement) button.disabled = count === 0;
}

/** Add a bounded selection serially through canonical single-item mutations.
 * Recheck membership before each item, including songs added by an earlier album.
 * @param {MouseEvent} event Explicit Add selected activation.
 * @returns {Promise<void>} Report completion or partial progress without nesting editors.
 */
async function addSelectedMusicCatalogItems(event) {
    const button = event.target instanceof Element
        ? event.target.closest('[data-music-add-selected]') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    const initialCard = button.closest('[data-gallery-music][data-music-editor="1"]');
    if (!initialCard || initialCard.dataset.musicBusy === '1') return;
    const galleryId = initialCard.dataset.musicGallery;
    if (musicCatalogBatchRuns.has(galleryId)) return;
    const selections = Array.from(new Set(Array.from(initialCard.querySelectorAll(
        '[data-music-catalog-select]:checked:not(:disabled)',
    ), input => input.value)));
    const status = initialCard.querySelector('[data-music-status]');
    if (selections.length > catalogBatchLimit) {
        if (status) status.textContent = initialCard.dataset.musicBatchLimit
            .replace('{count}', String(catalogBatchLimit));
        return;
    }
    if (selections.length === 0) return;
    musicCatalogBatchRuns.add(galleryId);
    let added = 0;
    let skipped = 0;
    try {
        for (const [index, selection] of selections.entries()) {
            const card = findMusicCard(galleryId);
            if (!card) return;
            const match = /^(add_song|add_album):([1-9][0-9]*)$/.exec(selection);
            if (!match) throw new Error(String(card.dataset.musicError));
            const currentStatus = card.querySelector('[data-music-status]');
            if (currentStatus) currentStatus.textContent = card.dataset.musicBatchProgress
                .replace('{current}', String(index + 1))
                .replace('{total}', String(selections.length));
            const url = new URL(card.dataset.selectionStatusUrl, location.href);
            if (url.origin !== location.origin) throw new Error('Invalid catalog origin.');
            url.searchParams.set(match[1] === 'add_song' ? 'song_id' : 'album_id', match[2]);
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), catalogReadTimeoutMs);
            let availability;
            try {
                const response = await fetch(url, {
                    method: 'GET', credentials: 'same-origin', cache: 'no-store',
                    signal: controller.signal,
                    headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                });
                availability = await response.json();
                if (!response.ok || availability.ok !== true
                    || typeof availability.already_added !== 'boolean') {
                    throw new Error(String(card.dataset.musicSearchError));
                }
            } finally {
                clearTimeout(timer);
            }
            if (!card.isConnected) return;
            if (availability.already_added) {
                skipped += 1;
                continue;
            }
            const form = card.querySelector('.gallery-music-settings');
            if (!(form instanceof HTMLFormElement)) throw new Error(String(card.dataset.musicError));
            const body = new FormData(form);
            body.set('music_action', match[1]);
            body.set('selection_id', match[2]);
            body.set('catalog_revision', card.querySelector('[name="catalog_revision"]')?.value || '');
            const success = await submitGalleryMusicMutation(
                card, body, form.getAttribute('action'),
            );
            if (!success) throw new Error(String(
                findMusicCard(galleryId)?.querySelector('[data-music-status]')?.textContent
                    || card.dataset.musicError,
            ));
            added += 1;
        }
        const card = findMusicCard(galleryId);
        const output = card?.querySelector('[data-music-status]');
        if (output) output.textContent = card.dataset.musicBatchComplete
            .replace('{count}', String(added)).replace('{skipped}', String(skipped));
    } catch (error) {
        const card = findMusicCard(galleryId);
        const output = card?.querySelector('[data-music-status]');
        if (output) output.textContent = card.dataset.musicBatchFailed
            .replace('{count}', String(added))
            .replace('{total}', String(selections.length))
            .replace('{message}', error instanceof Error ? error.message : '');
    } finally {
        musicCatalogBatchRuns.delete(galleryId);
    }
}

/** Load one bounded album page when expanded or explicitly continued.
 * @param {Event} event Native details toggle or next-page button click.
 * @returns {Promise<void>} Replace or append owned rows, or expose retry feedback.
 */
async function loadMusicCatalogAlbum(event) {
    const target = event.target instanceof Element ? event.target : null;
    const more = target?.closest('[data-music-album-more]');
    const album = event.type === 'toggle'
        ? target?.closest('[data-music-catalog-album]')
        : more?.closest('[data-music-catalog-album]');
    if (!(album instanceof HTMLDetailsElement) || !album.open
        || album.dataset.loading === '1'
        || (event.type === 'toggle' && album.dataset.loaded === '1')) return;
    if (event.type === 'click') {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
    const results = album.querySelector('[data-music-album-results]');
    const next = album.querySelector('[data-music-album-more]');
    if (!results || !(next instanceof HTMLButtonElement)) return;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), catalogReadTimeoutMs);
    const offset = Number(album.dataset.nextOffset || '0');
    album.dataset.loading = '1';
    album.setAttribute('aria-busy', 'true');
    next.disabled = true;
    try {
        const url = new URL(album.dataset.membersUrl, location.href);
        if (url.origin !== location.origin) throw new Error('Invalid album origin.');
        url.searchParams.set('offset', String(offset));
        const response = await fetch(url, {
            method: 'GET', credentials: 'same-origin', cache: 'no-store',
            signal: controller.signal,
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        });
        const result = await response.json();
        if (!album.isConnected) return;
        if (!response.ok || result.ok !== true
            || typeof result.choices_html !== 'string'
            || (result.next_offset !== null
                && (!Number.isSafeInteger(result.next_offset)
                    || result.next_offset <= offset))) throw new Error('Invalid album page.');
        results.querySelector('[data-music-album-error]')?.remove();
        if (offset === 0) results.innerHTML = result.choices_html;
        else results.insertAdjacentHTML('beforeend', result.choices_html);
        album.dataset.loaded = '1';
        next.hidden = result.next_offset === null;
        album.dataset.nextOffset = String(result.next_offset ?? offset);
    } catch {
        if (!album.isConnected) return;
        let error = results.querySelector('[data-music-album-error]');
        if (!error) {
            error = document.createElement('p');
            error.dataset.musicAlbumError = '';
            error.setAttribute('role', 'status');
            results.append(error);
        }
        error.textContent = album.dataset.loadError;
        next.hidden = false;
    } finally {
        clearTimeout(timer);
        album.dataset.loading = '0';
        album.removeAttribute('aria-busy');
        next.disabled = false;
    }
}

/**
 * Queue the latest requested music preference without overlapping writes.
 * @param {Event} event Change event from an editor music preference switch.
 * @returns {void} Starts or updates that gallery's sequential save queue.
 */
function saveMusicPreferenceChange(event) {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)
        || !input.matches('[data-music-default-switch]')) return;
    const card = input.closest('[data-gallery-music][data-music-editor="1"]');
    if (!card) return;
    if (musicCatalogBatchRuns.has(card.dataset.musicGallery)) {
        input.checked = card.dataset.musicEnabled === '1';
        return;
    }
    const galleryId = String(card.dataset.musicGallery || '');
    if (!galleryId) return;
    let state = musicPreferenceSaves.get(galleryId);
    if (!state || !state.running) {
        state = {
            desired: input.checked,
            confirmed: card.dataset.musicEnabled === '1',
            running: false,
            synchronized: true,
        };
        musicPreferenceSaves.set(galleryId, state);
    }
    if (card.dataset.musicRefreshBlocked === '1') {
        input.checked = state.confirmed;
        const notice = card.querySelector('[data-music-preference-status]');
        if (notice) notice.textContent = String(card.dataset.musicRefreshError
            || card.dataset.musicError || '');
        return;
    }
    state.desired = input.checked;
    if (state.desired === state.confirmed && !state.running) return;
    if (!state.running) void drainMusicPreferenceSaves(galleryId, state);
}

/**
 * Save one latest preference at a time through the existing music endpoint.
 * @param {string} galleryId Stable gallery identity owning this save queue.
 * @param {MusicPreferenceSaveState} state Latest desired and server-confirmed preference state.
 * @returns {Promise<void>} Resolves after all queued intent and completion work settles.
 */
async function drainMusicPreferenceSaves(galleryId, state) {
    state.running = true;
    let failed = false;
    try {
        while (state.desired !== state.confirmed) {
            const intent = state.desired;
            const card = findMusicCard(galleryId);
            const form = card?.querySelector('.gallery-music-settings');
            if (!card || !(form instanceof HTMLFormElement)) {
                state.desired = state.confirmed;
                failed = true;
                break;
            }
            const status = card.querySelector('[data-music-preference-status]');
            if (status) {
                status.textContent = String(
                    card.dataset.musicPreferenceSaving || card.dataset.musicError || '',
                );
            }
            card.dataset.musicBusy = '1';
            try {
                const body = new FormData(form);
                body.set('music_action', 'settings');
                body.set('music_enabled', intent ? '1' : '0');
                body.set('ajax', '1');
                const response = await fetch(form.getAttribute('action'), {
                    method: 'POST', body, credentials: 'same-origin',
                    headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                });
                let result;
                try { result = await response.json(); }
                catch { throw new Error(String(card.dataset.musicError || '')); }
                if (!response.ok || result.ok !== true) {
                    throw new Error(String(result.message || card.dataset.musicError || ''));
                }

                state.confirmed = intent;
                if (typeof result.music_revision === 'string') {
                    const revision = form.querySelector('[name="music_revision"]');
                    if (revision instanceof HTMLInputElement) {
                        revision.value = result.music_revision;
                    }
                }
                card.dataset.musicEnabled = intent ? '1' : '0';
                const refreshPanel = async (_panel, _envelope, guard) => {
                    const currentCard = findMusicCard(galleryId);
                    if (!guard.isCurrent() || !currentCard
                        || typeof result.panel_html !== 'string') return false;
                    const template = document.createElement('template');
                    template.innerHTML = result.panel_html;
                    const replacement = template.content.querySelector(
                        '[data-gallery-music][data-music-editor="1"]',
                    );
                    if (!replacement) return false;
                    replacement.dataset.musicBusy = '1';
                    const replacementSwitch = replacement.querySelector(
                        '[data-music-default-switch]',
                    );
                    if (replacementSwitch instanceof HTMLInputElement
                        && state.desired !== state.confirmed) {
                        replacementSwitch.checked = state.desired;
                    }
                    currentCard.replaceWith(replacement);
                    return true;
                };
                try {
                    const completion = await completeAdminMutation(
                        result,
                        {refreshPanel},
                    );
                    state.synchronized = completion.synchronized === true;
                } catch {
                    state.synchronized = false;
                }
                if (!state.synchronized) {
                    const currentCard = findMusicCard(galleryId);
                    if (currentCard) currentCard.dataset.musicRefreshBlocked = '1';
                    state.desired = state.confirmed;
                    const currentSwitch = currentCard?.querySelector(
                        '[data-music-default-switch]',
                    );
                    if (currentSwitch instanceof HTMLInputElement) {
                        currentSwitch.checked = state.confirmed;
                    }
                }
            } catch (error) {
                failed = true;
                const currentCard = findMusicCard(galleryId);
                const currentSwitch = currentCard?.querySelector(
                    '[data-music-default-switch]',
                );
                if (currentSwitch instanceof HTMLInputElement) {
                    currentSwitch.checked = state.confirmed;
                }
                state.desired = state.confirmed;
                const currentStatus = currentCard?.querySelector(
                    '[data-music-preference-status]',
                );
                if (currentStatus) {
                    currentStatus.textContent = error instanceof Error
                        ? error.message
                        : String(currentCard?.dataset.musicError || '');
                }
                break;
            } finally {
                const currentCard = findMusicCard(galleryId);
                if (currentCard) currentCard.dataset.musicBusy = '0';
            }
        }
        const card = findMusicCard(galleryId);
        const status = card?.querySelector('[data-music-preference-status]');
        if (!failed && status && state.desired === state.confirmed) {
            status.textContent = state.synchronized
                ? String(card?.dataset.musicPreferenceSaved || '')
                : String(card?.dataset.musicRefreshError || card?.dataset.musicError || '');
        }
    } finally {
        state.running = false;
        if (state.desired !== state.confirmed) {
            void drainMusicPreferenceSaves(galleryId, state);
        }
    }
}

/**
 * Find the currently mounted music editor for one gallery.
 * @param {string} galleryId Stable gallery identity from the delegated control.
 * @returns {HTMLElement|null} Current card or null when the editor is absent.
 */
function findMusicCard(galleryId) {
    for (const card of document.querySelectorAll(
        '[data-gallery-music][data-music-gallery][data-music-editor="1"]',
    )) {
        if (card instanceof HTMLElement
            && card.dataset.musicGallery === galleryId) return card;
    }
    return null;
}

/**
 * Preserve the selected Media subtab in the native gallery settings form.
 * @param {MouseEvent|KeyboardEvent} event Activation of a Media subtab.
 * @returns {void} Updates the hidden section value owned by that editor scope.
 */
function updateGalleryMediaSectionReturn(event) {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const tab = target.closest('[data-admin-media-section]');
    if (!(tab instanceof HTMLElement)) return;
    if (event instanceof KeyboardEvent
        && tab.getAttribute('aria-selected') !== 'true') return;
    const scope = tab.closest('[data-admin-subtab-scope]');
    const input = scope?.querySelector('input[name="return_media_section"]');
    if (input instanceof HTMLInputElement) {
        input.value = String(tab.dataset.adminMediaSection || '');
    }
}

/**
 * Submit one gallery playlist mutation and preserve shared completion state.
 * @param {SubmitEvent} event Submission from an owned gallery music form.
 * @returns {Promise<void>} Completes after the mutation and panel refresh settle.
 */
async function handleGalleryMusicMutationSubmit(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)
        || !form.matches('[data-gallery-music-form]')) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    const card = form.closest('[data-gallery-music][data-music-editor="1"]');
    if (!card || musicCatalogBatchRuns.has(card.dataset.musicGallery)) return;
    const submitter = event.submitter || form.querySelector(
        'button[type="submit"]:not(:disabled)',
    );
    const body = new FormData(form);
    if (submitter?.name) body.set(submitter.name, submitter.value);
    await submitGalleryMusicMutation(card, body, form.getAttribute('action'));
}

/** Submit one canonical mutation against the current editor root.
 * @param {HTMLElement} card Current outer editor; catalog players never own writes.
 * @param {FormData} body Authenticated form fields and one explicit music action.
 * @param {string|null} endpoint Prepared form action for this gallery.
 * @returns {Promise<boolean>} True only after persistence and canonical refresh succeed.
 */
async function submitGalleryMusicMutation(card, body, endpoint) {
    if (card.dataset.musicBusy === '1') return false;
    if (card.dataset.musicRefreshBlocked === '1') {
        const blocked = card.querySelector('[data-music-status]');
        if (blocked) blocked.textContent = String(card.dataset.musicRefreshError
            || card.dataset.musicError || '');
        return false;
    }
    body.set('ajax', '1');
    body.set('music_query', card.querySelector('[data-music-catalog-query]')?.value || '');
    const buttons = Array.from(card.querySelectorAll(
        'button, [data-music-default-switch]',
    ));
    const disabled = buttons.map(button => button.disabled);
    card.dataset.musicBusy = '1';
    buttons.forEach(button => { button.disabled = true; });
    const status = card.querySelector('[data-music-status]');
    if (status) status.textContent = '';
    try {
        // Read the attribute: a future control called "action" must not
        // shadow the form's endpoint through named DOM property access.
        const response = await fetch(endpoint, {
            method: 'POST', body, credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        });
        let result;
        try { result = await response.json(); }
        catch { throw new Error(String(card.dataset.musicError || '')); }
        if (!response.ok || result.ok !== true) {
            throw new Error(String(result.message || card.dataset.musicError));
        }
        // The shared coordinator owns envelope validation. This adapter
        // replaces only the connected music card while its guard is current.
        const refreshPanel = async (_panel, _envelope, guard) => {
            if (!guard.isCurrent() || !card.isConnected
                || typeof result.panel_html !== 'string') return false;
            const template = document.createElement('template');
            template.innerHTML = result.panel_html;
            const replacement = template.content.querySelector(
                '[data-gallery-music][data-music-editor="1"]',
            );
            if (!replacement) return false;
            card.replaceWith(replacement);
            return true;
        };
        const completion = await completeAdminMutation(result, {refreshPanel});
        const currentCard = findMusicCard(
            String(card.dataset.musicGallery || ''),
        );
        if (currentCard) {
            const notice = currentCard.querySelector('[data-music-status]');
            if (notice) notice.textContent = String(result.message || '');
            if (completion.synchronized !== true) {
                currentCard.dataset.musicRefreshBlocked = '1';
                const refreshStatus = currentCard.querySelector('[data-music-status]');
                if (refreshStatus) refreshStatus.textContent = String(
                    currentCard.dataset.musicRefreshError || currentCard.dataset.musicError || ''
                );
            }
        }
        return completion.synchronized === true;
    } catch (error) {
        if (status) status.textContent = error instanceof Error
            ? error.message : String(card.dataset.musicError || '');
        return false;
    } finally {
        card.dataset.musicBusy = '0';
        buttons.forEach((button, index) => { button.disabled = disabled[index]; });
    }
}

/**
 * Search the bounded music chooser and replace only its server-rendered rows.
 * @param {SubmitEvent} event The submit event for a catalog search form.
 * @returns {Promise<void>} Completes after replacing results or reporting an error.
 */
async function submitMusicCatalogSearch(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)
        || !form.matches('[data-gallery-music-search]')) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    const card = form.closest('[data-gallery-music][data-music-editor="1"]');
    const results = card?.querySelector('[data-music-catalog-results]');
    if (!card || !results || card.dataset.musicBusy === '1'
        || musicCatalogBatchRuns.has(card.dataset.musicGallery)) return;
    const body = new FormData(form);
    body.set('music_action', 'choices');
    body.set('ajax', '1');
    card.dataset.musicBusy = '1';
    const preference = card.querySelector('[data-music-default-switch]');
    const preferenceWasDisabled = preference?.disabled === true;
    if (preference instanceof HTMLInputElement) preference.disabled = true;
    try {
        const response = await fetch(form.getAttribute('action'), {
            method: 'POST', body, credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        });
        const result = await response.json();
        if (!response.ok || result.ok !== true
            || typeof result.choices_html !== 'string') {
            throw new Error(String(result.message || card.dataset.musicSearchError
                || card.dataset.musicError || ''));
        }
        if (results.querySelector('[data-music-action][aria-pressed="true"]')) {
            stopGalleryMusicViewer();
        }
        results.innerHTML = result.choices_html;
        const count = card.querySelector('[data-music-selection-count]');
        const add = card.querySelector('[data-music-add-selected]');
        if (count) count.textContent = '';
        if (add instanceof HTMLButtonElement) add.disabled = true;
    } catch {
        const status = card.querySelector('[data-music-status]');
        if (status) status.textContent = String(
            card.dataset.musicSearchError || card.dataset.musicError || ''
        );
    } finally {
        card.dataset.musicBusy = '0';
        if (preference instanceof HTMLInputElement) {
            preference.disabled = preferenceWasDisabled;
        }
    }
}
