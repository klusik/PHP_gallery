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
import {completeAdminMutation} from './admin-mutation-completion.js?v=20261016-three-state-covers-v1';

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
    document.addEventListener('submit', submitMusicCatalogSearch, true);
    document.addEventListener('submit', handleGalleryMusicMutationSubmit, true);
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
        const card = form.closest('[data-gallery-music]');
        if (!card || card.dataset.musicBusy === '1') return;
        if (card.dataset.musicRefreshBlocked === '1') {
            const blocked = card.querySelector('[data-music-status]');
            if (blocked) blocked.textContent = String(card.dataset.musicRefreshError
                || card.dataset.musicError || '');
            return;
        }
        const submitter = event.submitter || form.querySelector(
            'button[type="submit"]:not(:disabled)'
        );
        const body = new FormData(form);
        if (submitter?.name) body.set(submitter.name, submitter.value);
        body.set('ajax', '1');
        const buttons = Array.from(card.querySelectorAll('button'));
        const disabled = buttons.map(button => button.disabled);
        card.dataset.musicBusy = '1';
        buttons.forEach(button => { button.disabled = true; });
        const status = card.querySelector('[data-music-status]');
        if (status) status.textContent = '';
        try {
            // Read the attribute: a future control called "action" must not
            // shadow the form's endpoint through named DOM property access.
            const response = await fetch(form.getAttribute('action'), {
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
                const replacement = template.content.querySelector('[data-gallery-music]');
                if (!replacement) return false;
                card.replaceWith(replacement);
                return true;
            };
            const completion = await completeAdminMutation(result, {refreshPanel});
            let currentCard = null;
            for (const candidate of document.querySelectorAll(
                '[data-gallery-music][data-music-gallery]'
            )) {
                if (candidate.dataset.musicGallery
                    === String(card.dataset.musicGallery || '')) {
                    currentCard = candidate;
                    break;
                }
            }
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
        } catch (error) {
            if (status) status.textContent = error instanceof Error
                ? error.message : String(card.dataset.musicError || '');
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
    const card = form.closest('[data-gallery-music]');
    const results = card?.querySelector('[data-music-catalog-results]');
    if (!card || !results || card.dataset.musicBusy === '1') return;
    const body = new FormData(form);
    body.set('music_action', 'choices');
    body.set('ajax', '1');
    card.dataset.musicBusy = '1';
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
        results.innerHTML = result.choices_html;
    } catch {
        const status = card.querySelector('[data-music-status]');
        if (status) status.textContent = String(
            card.dataset.musicSearchError || card.dataset.musicError || ''
        );
    } finally {
        card.dataset.musicBusy = '0';
    }
}
