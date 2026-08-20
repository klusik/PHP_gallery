/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/viewer-favourites.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Synchronizes Phase 1.1 viewer-favourite controls across cards and the lightbox.
 *
 * Responsibilities:
 *   - Submit viewer-CSRF-protected favourite forms without leaving the gallery
 *   - Keep duplicate representations of one image in the same favourite state
 *   - Follow the active lightbox image without owning lightbox navigation state
 *   - Fall back to normal HTML form submission when JavaScript is unavailable
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - The server remains authoritative for viewer identity, quota, and source authorization.
 *   - This module never decides whether a viewer may access an image or gallery.
 *
 * Last Updated:
 *   2026-08-18
 */

/**
 * Return a translated browser string with a safe English fallback.
 *
 * @param {string} key Translation key.
 * @param {string} fallback English fallback.
 * @return {string} Browser-facing text.
 */
function favouriteI18n(key, fallback) {
    const root = window.PHP_GALLERY_I18N && typeof window.PHP_GALLERY_I18N === 'object' ? window.PHP_GALLERY_I18N : {};
    const strings = root.strings && typeof root.strings === 'object' ? root.strings : {};
    return typeof strings[key] === 'string' ? strings[key] : fallback;
}

/**
 * Apply one authoritative favourite state to a rendered form.
 *
 * @param {HTMLFormElement} form Managed favourite form.
 * @param {number} imageId Canonical image id.
 * @param {boolean} favourite Current server state.
 */
function updateFavouriteForm(form, imageId, favourite) {
    const imageInput = form.querySelector('[data-viewer-favourite-image-id]');
    const actionInput = form.querySelector('[data-viewer-favourite-action]');
    const button = form.querySelector('[data-viewer-favourite-button]');
    const icon = form.querySelector('[data-viewer-favourite-icon]');
    const label = form.querySelector('[data-viewer-favourite-label]');
    if (!(imageInput instanceof HTMLInputElement) || !(actionInput instanceof HTMLInputElement) || !(button instanceof HTMLButtonElement)) {
        return;
    }

    const text = favourite
        ? favouriteI18n('viewer.favourites.remove', 'Remove from favourites')
        : favouriteI18n('viewer.favourites.add', 'Add to favourites');
    form.dataset.imageId = String(imageId);
    form.dataset.favourite = favourite ? '1' : '0';
    form.classList.toggle('is-favourite', favourite);
    imageInput.value = String(imageId);
    actionInput.value = favourite ? 'remove' : 'add';
    button.setAttribute('aria-pressed', favourite ? 'true' : 'false');
    button.setAttribute('aria-label', text);
    button.title = text;
    if (icon) {
        icon.textContent = favourite ? '♥' : '♡';
    }
    if (label) {
        label.textContent = text;
    }
}

/**
 * Find the current favourite state attached to any source representation of one image.
 *
 * @param {number} imageId Canonical image id.
 * @return {boolean|null} Favourite state, or null when no personalized source exists.
 */
function favouriteStateForImage(imageId) {
    const candidates = document.querySelectorAll(`[data-image-id="${imageId}"][data-viewer-favourite]`);
    for (const candidate of candidates) {
        if (candidate.dataset.viewerFavourite === '1') {
            return true;
        }
        if (candidate.dataset.viewerFavourite === '0') {
            return false;
        }
    }
    return null;
}

/**
 * Synchronize all card/source/form representations after a successful mutation.
 *
 * @param {number} imageId Canonical image id.
 * @param {boolean} favourite Authoritative server state.
 */
function syncFavouriteState(imageId, favourite) {
    document.querySelectorAll(`[data-image-id="${imageId}"]`).forEach((node) => {
        if (node instanceof HTMLElement) {
            node.dataset.viewerFavourite = favourite ? '1' : '0';
        }
    });
    document.querySelectorAll(`[data-viewer-favourite-form][data-image-id="${imageId}"]`).forEach((form) => {
        if (form instanceof HTMLFormElement) {
            updateFavouriteForm(form, imageId, favourite);
        }
    });

    const overlay = document.querySelector('[data-lightbox]');
    const lightboxForm = document.querySelector('[data-viewer-favourite-lightbox-form]');
    if (overlay instanceof HTMLElement
        && lightboxForm instanceof HTMLFormElement
        && overlay.dataset.currentImageId === String(imageId)) {
        updateFavouriteForm(lightboxForm, imageId, favourite);
        lightboxForm.hidden = false;
    }
}

/**
 * Refresh the dedicated lightbox favourite form from the selected lightbox source node.
 */
function refreshLightboxFavouriteForm() {
    const overlay = document.querySelector('[data-lightbox]');
    const form = document.querySelector('[data-viewer-favourite-lightbox-form]');
    if (!(overlay instanceof HTMLElement) || !(form instanceof HTMLFormElement)) {
        return;
    }

    const imageId = Number.parseInt(overlay.dataset.currentImageId || '0', 10);
    if (!Number.isInteger(imageId) || imageId <= 0) {
        form.hidden = true;
        return;
    }
    const favourite = favouriteStateForImage(imageId);
    if (favourite === null) {
        form.hidden = true;
        return;
    }
    updateFavouriteForm(form, imageId, favourite);
    form.hidden = false;
}

/**
 * Submit one favourite form and synchronize all representations from the JSON result.
 *
 * @param {HTMLFormElement} form Submitted form.
 */
async function submitFavouriteForm(form) {
    if (form.dataset.submitting === '1') {
        return;
    }
    const button = form.querySelector('[data-viewer-favourite-button]');
    form.dataset.submitting = '1';
    if (button instanceof HTMLButtonElement) {
        button.disabled = true;
    }

    try {
        const payload = new FormData(form);
        payload.set('ajax', '1');
        const response = await fetch(form.action, {
            method: 'POST',
            body: payload,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        let result = null;
        try {
            result = await response.json();
        } catch {
            result = null;
        }

        if (!response.ok || !result || result.ok !== true) {
            if (response.status === 401 && typeof result?.login_url === 'string' && result.login_url !== '') {
                window.location.assign(result.login_url);
                return;
            }
            window.alert(typeof result?.error === 'string'
                ? result.error
                : favouriteI18n('viewer.favourites.unavailable', 'Favourites are temporarily unavailable.'));
            return;
        }

        const imageId = Number.parseInt(String(result.image_id || '0'), 10);
        if (Number.isInteger(imageId) && imageId > 0) {
            syncFavouriteState(imageId, result.favourite === true);
        }
    } catch {
        window.alert(favouriteI18n('viewer.favourites.unavailable', 'Favourites are temporarily unavailable.'));
    } finally {
        delete form.dataset.submitting;
        if (button instanceof HTMLButtonElement) {
            button.disabled = false;
        }
    }
}

/**
 * Bind Phase 1.1 viewer-favourite browser behavior once per document.
 */
export function setupViewerFavourites() {
    if (document.documentElement.dataset.viewerFavouritesBound === '1') {
        return;
    }
    if (!document.querySelector('[data-viewer-favourite-form]')) {
        return;
    }
    document.documentElement.dataset.viewerFavouritesBound = '1';

    document.addEventListener('submit', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const form = target?.closest('[data-viewer-favourite-form]');
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        void submitFavouriteForm(form);
    }, true);

    const overlay = document.querySelector('[data-lightbox]');
    if (overlay instanceof HTMLElement && document.querySelector('[data-viewer-favourite-lightbox-form]')) {
        const observer = new MutationObserver(refreshLightboxFavouriteForm);
        observer.observe(overlay, {attributes: true, attributeFilter: ['data-current-image-id', 'hidden']});
        refreshLightboxFavouriteForm();
    }
}
