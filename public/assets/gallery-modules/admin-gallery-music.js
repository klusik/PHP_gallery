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
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)
            || !form.matches('[data-gallery-music-form]')) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const card = form.closest('[data-gallery-music]');
        if (!card || card.dataset.musicBusy === '1') return;
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
        const status = form.querySelector('[data-music-status]');
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
            await completeAdminMutation(result, {refreshPanel: async (_panel, _envelope, guard) => {
                if (!guard.isCurrent() || !card.isConnected
                    || typeof result.panel_html !== 'string') return false;
                const template = document.createElement('template');
                template.innerHTML = result.panel_html;
                const replacement = template.content.querySelector('[data-gallery-music]');
                if (!replacement) return false;
                card.replaceWith(replacement);
                return true;
            }});
        } catch (error) {
            if (status) status.textContent = error instanceof Error
                ? error.message : String(card.dataset.musicError || '');
        } finally {
            card.dataset.musicBusy = '0';
            buttons.forEach((button, index) => { button.disabled = disabled[index]; });
        }
    }, true);
}
