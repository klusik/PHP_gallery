/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/public-home-search.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Controls the optional public live search field.
 *
 * Responsibilities:
 *   - Debounce typing before search requests
 *   - Render compact gallery and photo result links
 *   - Keep the public page usable when search is disabled or unavailable
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
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-28
 */

/**
 * Attach behavior to every public search widget present on the current page.
 */
export function setupPublicHomeSearch() {
    const roots = document.querySelectorAll('[data-public-home-search]');
    for (const root of roots) {
        if (root instanceof HTMLElement) {
            setupOnePublicSearch(root);
        }
    }
}

/**
 * Attach behavior to one public search widget.
 *
 * @param {HTMLElement} root Search widget root element.
 * @return {void} Result value for the caller.
 */
function setupOnePublicSearch(root) {
    const input = root.querySelector('[data-public-home-search-input]');
    const results = root.querySelector('[data-public-home-search-results]');
    const clearButton = root.querySelector('[data-public-home-search-clear]');
    const contextCheckbox = root.querySelector('[data-public-home-search-context]');
    const searchUrl = root.getAttribute('data-search-url') || '';
    const galleryId = root.getAttribute('data-gallery-id') || '';
    const minLength = Number.parseInt(root.getAttribute('data-min-length') || '2', 10);
    const delayMs = Number.parseInt(root.getAttribute('data-delay-ms') || '200', 10);

    if (!(input instanceof HTMLInputElement) || !(results instanceof HTMLElement) || searchUrl === '') {
        return;
    }

    let timer = 0;
    let controller = null;
    let lastIssuedQuery = '';

    /**
     * Set clear visibility.
     *
     * Used by browser-side gallery behavior.
     */
    const setClearVisibility = () => {
        if (clearButton instanceof HTMLButtonElement) {
            clearButton.hidden = input.value.trim() === '';
        }
    };

    /**
     * Handle hide results.
     *
     * Used by browser-side gallery behavior.
     */
    const hideResults = () => {
        results.hidden = true;
        results.innerHTML = '';
        root.classList.remove('has-results', 'is-loading');
    };

    /**
     * Render status.
     *
     * Used by browser-side gallery behavior.
     *
     * @param {string} message Message value.
     */
    const renderStatus = (message) => {
        results.hidden = false;
        results.innerHTML = '';
        const status = document.createElement('div');
        status.className = 'public-home-search-status';
        status.textContent = message;
        results.append(status);
        root.classList.add('has-results');
    };

    /**
     * Render results.
     *
     * Used by browser-side gallery behavior.
     *
     * @param {object} payload Payload value.
     * @param {*} query Query value.
     */
    const renderResults = (payload, query) => {
        if (query !== lastIssuedQuery) {
            return;
        }
        root.classList.remove('is-loading');
        results.innerHTML = '';

        const items = Array.isArray(payload.results) ? payload.results : [];
        if (items.length === 0) {
            renderStatus(root.getAttribute('data-empty-label') || 'No matches found.');
            return;
        }

        const list = document.createElement('div');
        list.className = 'public-home-search-result-list';
        for (const item of items) {
            const link = document.createElement('a');
            link.className = 'public-home-search-result';
            link.href = typeof item.url === 'string' ? item.url : '#';

            const label = document.createElement('span');
            label.className = 'public-home-search-result-kind';
            label.textContent = typeof item.label === 'string' ? item.label : '';

            const text = document.createElement('span');
            text.className = 'public-home-search-result-text';

            const title = document.createElement('strong');
            title.textContent = typeof item.title === 'string' ? item.title : '';
            text.append(title);

            if (typeof item.subtitle === 'string' && item.subtitle.trim() !== '') {
                const subtitle = document.createElement('small');
                subtitle.textContent = item.subtitle;
                text.append(subtitle);
            }

            link.append(label, text);
            list.append(link);
        }

        results.hidden = false;
        results.append(list);
        root.classList.add('has-results');
    };

    /**
     * Run search.
     *
     * Used by browser-side gallery behavior.
     *
     * @return {*} Result value for the caller.
     */
    const runSearch = () => {
        const query = input.value.trim();
        setClearVisibility();
        if (query.length < minLength) {
            if (controller) {
                controller.abort();
                controller = null;
            }
            hideResults();
            return;
        }

        if (controller) {
            controller.abort();
        }
        controller = new AbortController();
        lastIssuedQuery = query;
        root.classList.add('is-loading');
        renderStatus(root.getAttribute('data-loading-label') || 'Searching...');

        const url = new URL(searchUrl, window.location.href);
        url.searchParams.set('q', query);
        if (galleryId !== '' && contextCheckbox instanceof HTMLInputElement && contextCheckbox.checked) {
            url.searchParams.set('gallery_id', galleryId);
            url.searchParams.set('context_only', '1');
        }

        fetch(url.toString(), {
            headers: {'Accept': 'application/json'},
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Search request failed with HTTP ${response.status}`);
                }
                return response.json();
            })
            .then((payload) => renderResults(payload, query))
            .catch((error) => {
                if (error.name === 'AbortError') {
                    return;
                }
                root.classList.remove('is-loading');
                renderStatus(root.getAttribute('data-error-label') || 'Search is temporarily unavailable.');
            });
    };

    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        setClearVisibility();
        timer = window.setTimeout(runSearch, delayMs);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            input.value = '';
            setClearVisibility();
            hideResults();
        }
    });

    if (clearButton instanceof HTMLButtonElement) {
        clearButton.addEventListener('click', () => {
            input.value = '';
            input.focus();
            setClearVisibility();
            hideResults();
        });
    }

    if (contextCheckbox instanceof HTMLInputElement) {
        contextCheckbox.addEventListener('change', () => {
            if (input.value.trim().length >= minLength) {
                window.clearTimeout(timer);
                runSearch();
            }
        });
    }

    document.addEventListener('click', (event) => {
        if (event.target instanceof Node && root.contains(event.target)) {
            return;
        }
        root.classList.remove('has-results');
    });

    input.addEventListener('focus', () => {
        if (results.childElementCount > 0) {
            root.classList.add('has-results');
            results.hidden = false;
        }
    });

    setClearVisibility();
}
