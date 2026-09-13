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
 *   - Render fast primary/media results before deferred descriptive/deep search completes
 *   - Merge staged responses deterministically without stale-query races
 *   - Reconcile stable result nodes so late phases do not recreate the complete result list
 *   - Expose busy/status accessibility state and reduced-motion-safe progressive insertion feedback
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
 *   2026-09-13
 */

/**
 * Return one stable identity for client-side result merging.
 *
 * @param {object} item Search result item.
 * @return {string} Stable merge key.
 */
export function publicSearchResultIdentity(item) {
    if (item && typeof item.key === 'string' && item.key.trim() !== '') {
        return item.key;
    }
    const type = item && typeof item.type === 'string' ? item.type : 'result';
    const url = item && typeof item.url === 'string' ? item.url : '';
    const title = item && typeof item.title === 'string' ? item.title : '';
    return `${type}:${url}:${title}`;
}

/**
 * Compare public search result models using stable relevance ordering.
 *
 * @param {object} left Left result model.
 * @param {object} right Right result model.
 * @return {number} Sort comparator result.
 */
export function comparePublicSearchResults(left, right) {
    const leftRank = Number.isFinite(Number(left?.rank)) ? Number(left.rank) : 0;
    const rightRank = Number.isFinite(Number(right?.rank)) ? Number(right.rank) : 0;
    if (leftRank !== rightRank) {
        return rightRank - leftRank;
    }

    const leftType = left?.type === 'gallery' ? 0 : 1;
    const rightType = right?.type === 'gallery' ? 0 : 1;
    if (leftType !== rightType) {
        return leftType - rightType;
    }

    const titleCompare = String(left?.title || '').localeCompare(String(right?.title || ''), undefined, {sensitivity: 'base'});
    if (titleCompare !== 0) {
        return titleCompare;
    }
    return publicSearchResultIdentity(left).localeCompare(publicSearchResultIdentity(right));
}

/**
 * Enforce compact cross-phase result limits after relevance sorting.
 *
 * @param {Array<object>} items Relevance-sorted result models.
 * @param {number} totalLimit Maximum total visible items.
 * @param {number} galleryLimit Maximum visible gallery items.
 * @param {number} photoLimit Maximum visible photo items.
 * @return {Array<object>} Bounded result models.
 */
export function limitPublicSearchResults(items, totalLimit = 14, galleryLimit = 8, photoLimit = 8) {
    const bounded = [];
    let galleries = 0;
    let photos = 0;
    for (const item of Array.isArray(items) ? items : []) {
        if (bounded.length >= totalLimit) {
            break;
        }
        if (item?.type === 'gallery') {
            if (galleries >= galleryLimit) {
                continue;
            }
            galleries += 1;
        } else if (item?.type === 'photo') {
            if (photos >= photoLimit) {
                continue;
            }
            photos += 1;
        }
        bounded.push(item);
    }
    return bounded;
}

/**
 * Merge one staged result array into the currently visible result set.
 *
 * Later phases may enrich a duplicate item, while the highest relevance rank
 * observed for the stable entity is retained.
 *
 * @param {Array<object>} current Existing result models.
 * @param {Array<object>} incoming Newly received result models.
 * @return {Array<object>} Deduplicated and relevance-sorted result models.
 */
export function mergePublicSearchResults(current, incoming) {
    const merged = new Map();
    for (const item of Array.isArray(current) ? current : []) {
        merged.set(publicSearchResultIdentity(item), {...item});
    }
    for (const item of Array.isArray(incoming) ? incoming : []) {
        const key = publicSearchResultIdentity(item);
        const existing = merged.get(key);
        if (!existing) {
            merged.set(key, {...item, key});
            continue;
        }
        const existingRank = Number.isFinite(Number(existing.rank)) ? Number(existing.rank) : 0;
        const incomingRank = Number.isFinite(Number(item?.rank)) ? Number(item.rank) : 0;
        merged.set(key, {
            ...existing,
            ...item,
            key,
            rank: Math.max(existingRank, incomingRank),
        });
    }
    return limitPublicSearchResults(Array.from(merged.values()).sort(comparePublicSearchResults));
}

/**
 * Return whether an asynchronous response still belongs to the active query.
 *
 * @param {number} responseGeneration Generation captured before the request.
 * @param {number} activeGeneration Current generation.
 * @param {string} responseQuery Query captured before the request.
 * @param {string} activeQuery Current active query.
 * @return {boolean} True only for the active search generation.
 */
export function publicSearchResponseIsCurrent(responseGeneration, activeGeneration, responseQuery, activeQuery) {
    return responseGeneration === activeGeneration && responseQuery === activeQuery;
}

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
    const mediaDelayMs = Number.parseInt(root.getAttribute('data-media-delay-ms') || '100', 10);
    const descriptiveDelayMs = Number.parseInt(root.getAttribute('data-descriptive-delay-ms') || '300', 10);
    const deepDelayMs = Number.parseInt(root.getAttribute('data-deep-delay-ms') || '550', 10);

    if (!(input instanceof HTMLInputElement) || !(results instanceof HTMLElement) || searchUrl === '') {
        return;
    }

    let timer = 0;
    let mediaTimer = 0;
    let descriptiveTimer = 0;
    let deepTimer = 0;
    let primaryController = null;
    let mediaController = null;
    let descriptiveController = null;
    let deepController = null;
    let activeGeneration = 0;
    let activeQuery = '';
    let visibleItems = [];
    let pendingPhases = new Set();
    let completedPhases = new Set();
    let failedPhases = new Set();
    let saveSmartGalleryUrl = '';
    let saveSmartGalleryLabel = '';
    let renderedResultKeys = new Set();

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
     * Abort timers and requests owned by the previous search generation.
     *
     * Used by browser-side gallery behavior.
     */
    const abortSearchWork = () => {
        window.clearTimeout(timer);
        window.clearTimeout(mediaTimer);
        window.clearTimeout(descriptiveTimer);
        window.clearTimeout(deepTimer);
        timer = 0;
        mediaTimer = 0;
        descriptiveTimer = 0;
        deepTimer = 0;
        if (primaryController) {
            primaryController.abort();
            primaryController = null;
        }
        if (mediaController) {
            mediaController.abort();
            mediaController = null;
        }
        if (descriptiveController) {
            descriptiveController.abort();
            descriptiveController = null;
        }
        if (deepController) {
            deepController.abort();
            deepController = null;
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
        renderedResultKeys = new Set();
        root.classList.remove('has-results', 'is-loading');
        root.setAttribute('aria-busy', 'false');
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
        status.setAttribute('data-public-home-search-status', '');
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.textContent = message;
        results.append(status);
        root.classList.add('has-results');
    };

    /**
     * Update optional Smart Gallery action data from one search response.
     *
     * @param {object} payload Search response payload.
     * @return {void} Result value for the caller.
     */
    const captureSearchActions = (payload) => {
        if (typeof payload?.save_smart_gallery_url === 'string' && payload.save_smart_gallery_url !== '') {
            saveSmartGalleryUrl = payload.save_smart_gallery_url;
            saveSmartGalleryLabel = typeof payload.save_smart_gallery_label === 'string' ? payload.save_smart_gallery_label : 'Save search as Smart Gallery';
        }
    };

    /**
     * Render the currently merged staged result set.
     *
     * Progressive phases keep the result box useful while later work is still
     * pending. A late optional phase failure never replaces valid earlier results.
     *
     * @return {void} Result value for the caller.
     */
    const renderMergedResults = () => {
        const searchPending = pendingPhases.size > 0;
        root.classList.toggle('is-loading', searchPending);
        root.setAttribute('aria-busy', searchPending ? 'true' : 'false');

        if (visibleItems.length === 0) {
            if (searchPending) {
                renderStatus(root.getAttribute('data-loading-label') || 'Searching...');
                return;
            }
            if (completedPhases.size === 0 && failedPhases.size > 0) {
                renderStatus(root.getAttribute('data-error-label') || 'Search is temporarily unavailable.');
                return;
            }
            renderStatus(root.getAttribute('data-empty-label') || 'No matches found.');
            return;
        }

        results.hidden = false;
        let list = results.querySelector('[data-public-home-search-result-list]');
        if (!(list instanceof HTMLElement)) {
            results.innerHTML = '';
            list = document.createElement('div');
            list.className = 'public-home-search-result-list';
            list.setAttribute('data-public-home-search-result-list', '');
            results.append(list);
        }

        const existing = new Map();
        for (const node of list.querySelectorAll('[data-public-home-search-result-key]')) {
            if (node instanceof HTMLAnchorElement) {
                existing.set(node.getAttribute('data-public-home-search-result-key') || '', node);
            }
        }

        const nextKeys = new Set();
        for (const item of visibleItems) {
            const key = publicSearchResultIdentity(item);
            nextKeys.add(key);
            let link = existing.get(key);
            const isNew = !(link instanceof HTMLAnchorElement);
            if (!(link instanceof HTMLAnchorElement)) {
                link = document.createElement('a');
                link.className = 'public-home-search-result';
                link.setAttribute('data-public-home-search-result-key', key);

                const label = document.createElement('span');
                label.className = 'public-home-search-result-kind';

                const text = document.createElement('span');
                text.className = 'public-home-search-result-text';

                const title = document.createElement('strong');
                text.append(title);
                link.append(label, text);
            }

            link.href = typeof item.url === 'string' ? item.url : '#';
            const label = link.querySelector('.public-home-search-result-kind');
            const text = link.querySelector('.public-home-search-result-text');
            const title = text instanceof HTMLElement ? text.querySelector('strong') : null;
            if (label instanceof HTMLElement) {
                label.textContent = typeof item.label === 'string' ? item.label : '';
            }
            if (title instanceof HTMLElement) {
                title.textContent = typeof item.title === 'string' ? item.title : '';
            }
            if (text instanceof HTMLElement) {
                let subtitle = text.querySelector('small');
                const subtitleValue = typeof item.subtitle === 'string' ? item.subtitle.trim() : '';
                if (subtitleValue !== '') {
                    if (!(subtitle instanceof HTMLElement)) {
                        subtitle = document.createElement('small');
                        text.append(subtitle);
                    }
                    subtitle.textContent = subtitleValue;
                } else if (subtitle instanceof HTMLElement) {
                    subtitle.remove();
                }
            }

            list.append(link);
            if (isNew && !renderedResultKeys.has(key)) {
                link.classList.add('is-new');
                window.requestAnimationFrame(() => {
                    window.requestAnimationFrame(() => {
                        link.classList.remove('is-new');
                    });
                });
            }
        }

        for (const [key, node] of existing) {
            if (!nextKeys.has(key)) {
                node.remove();
            }
        }
        renderedResultKeys = nextKeys;

        let saveLink = list.querySelector('[data-public-search-save-smart-gallery]');
        if (saveSmartGalleryUrl !== '') {
            if (!(saveLink instanceof HTMLAnchorElement)) {
                saveLink = document.createElement('a');
                saveLink.className = 'button secondary public-search-save-smart-gallery';
                saveLink.setAttribute('data-public-search-save-smart-gallery', '');
            }
            saveLink.href = saveSmartGalleryUrl;
            saveLink.textContent = saveSmartGalleryLabel || 'Save search as Smart Gallery';
            list.append(saveLink);
        } else if (saveLink instanceof HTMLElement) {
            saveLink.remove();
        }

        let status = results.querySelector('[data-public-home-search-status]');
        if (searchPending) {
            if (!(status instanceof HTMLElement)) {
                status = document.createElement('div');
                status.className = 'public-home-search-status';
                status.setAttribute('data-public-home-search-status', '');
                status.setAttribute('role', 'status');
                status.setAttribute('aria-live', 'polite');
                results.append(status);
            }
            status.textContent = root.getAttribute('data-loading-label') || 'Searching...';
        } else if (status instanceof HTMLElement) {
            status.remove();
        }
        root.classList.add('has-results');
    };
    /**
     * Build one search URL while preserving the active gallery context restriction.
     *
     * @param {string} query Search query.
     * @param {string} phase Optional progressive phase identifier.
     * @return {URL} Search endpoint URL.
     */
    const buildSearchUrl = (query, phase = '') => {
        const url = new URL(searchUrl, window.location.href);
        url.searchParams.set('q', query);
        if (phase !== '') {
            url.searchParams.set('phase', phase);
        }
        if (galleryId !== '' && contextCheckbox instanceof HTMLInputElement && contextCheckbox.checked) {
            url.searchParams.set('gallery_id', galleryId);
            url.searchParams.set('context_only', '1');
        }
        return url;
    };

    /**
     * Merge one current-generation payload and repaint the visible result set.
     *
     * @param {object} payload Search response payload.
     * @return {void} Result value for the caller.
     */
    const mergePayload = (payload) => {
        const items = Array.isArray(payload?.results) ? payload.results : [];
        visibleItems = mergePublicSearchResults(visibleItems, items);
        captureSearchActions(payload);
    };

    /**
     * Mark one progressive phase complete for the active generation and repaint.
     *
     * @param {string} phase Stable phase identifier.
     * @param {boolean} succeeded Whether the phase returned a valid response.
     * @return {void} Result value for the caller.
     */
    const settlePhase = (phase, succeeded) => {
        pendingPhases.delete(phase);
        if (succeeded) {
            completedPhases.add(phase);
            failedPhases.delete(phase);
        } else {
            failedPhases.add(phase);
        }
        renderMergedResults();
    };

    /**
     * Request the fast primary phase for one generation.
     *
     * @param {number} generation Search generation.
     * @param {string} query Search query.
     * @return {void} Result value for the caller.
     */
    const requestPrimaryPhase = (generation, query) => {
        primaryController = new AbortController();
        fetch(buildSearchUrl(query, 'primary').toString(), {
            headers: {'Accept': 'application/json'},
            signal: primaryController.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Primary search request failed with HTTP ${response.status}`);
                }
                return response.json();
            })
            .then((payload) => {
                if (!publicSearchResponseIsCurrent(generation, activeGeneration, query, activeQuery)) {
                    return;
                }
                mergePayload(payload);
                settlePhase('primary', true);
            })
            .catch((error) => {
                if (error.name === 'AbortError') {
                    return;
                }
                if (!publicSearchResponseIsCurrent(generation, activeGeneration, query, activeQuery)) {
                    return;
                }
                settlePhase('primary', false);
            });
    };

    /**
     * Request the lightweight direct-image media phase for one generation.
     *
     * @param {number} generation Search generation.
     * @param {string} query Search query.
     * @return {void} Result value for the caller.
     */
    const requestMediaPhase = (generation, query) => {
        if (!publicSearchResponseIsCurrent(generation, activeGeneration, query, activeQuery)) {
            return;
        }
        mediaController = new AbortController();
        fetch(buildSearchUrl(query, 'media').toString(), {
            headers: {'Accept': 'application/json'},
            signal: mediaController.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Media search request failed with HTTP ${response.status}`);
                }
                return response.json();
            })
            .then((payload) => {
                if (!publicSearchResponseIsCurrent(generation, activeGeneration, query, activeQuery)) {
                    return;
                }
                mergePayload(payload);
                settlePhase('media', true);
            })
            .catch((error) => {
                if (error.name === 'AbortError') {
                    return;
                }
                if (!publicSearchResponseIsCurrent(generation, activeGeneration, query, activeQuery)) {
                    return;
                }
                settlePhase('media', false);
            });
    };

    /**
     * Request the deferred gallery-description phase for one generation.
     *
     * @param {number} generation Search generation.
     * @param {string} query Search query.
     * @return {void} Result value for the caller.
     */
    const requestDescriptivePhase = (generation, query) => {
        if (!publicSearchResponseIsCurrent(generation, activeGeneration, query, activeQuery)) {
            return;
        }
        descriptiveController = new AbortController();
        fetch(buildSearchUrl(query, 'descriptive').toString(), {
            headers: {'Accept': 'application/json'},
            signal: descriptiveController.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Descriptive search request failed with HTTP ${response.status}`);
                }
                return response.json();
            })
            .then((payload) => {
                if (!publicSearchResponseIsCurrent(generation, activeGeneration, query, activeQuery)) {
                    return;
                }
                mergePayload(payload);
                settlePhase('descriptive', true);
            })
            .catch((error) => {
                if (error.name === 'AbortError') {
                    return;
                }
                if (!publicSearchResponseIsCurrent(generation, activeGeneration, query, activeQuery)) {
                    return;
                }
                settlePhase('descriptive', false);
            });
    };

    /**
     * Request the late deep description/translation/AI phase for one generation.
     *
     * @param {number} generation Search generation.
     * @param {string} query Search query.
     * @return {void} Result value for the caller.
     */
    const requestDeepPhase = (generation, query) => {
        if (!publicSearchResponseIsCurrent(generation, activeGeneration, query, activeQuery)) {
            return;
        }
        deepController = new AbortController();
        fetch(buildSearchUrl(query, 'deep').toString(), {
            headers: {'Accept': 'application/json'},
            signal: deepController.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Deep search request failed with HTTP ${response.status}`);
                }
                return response.json();
            })
            .then((payload) => {
                if (!publicSearchResponseIsCurrent(generation, activeGeneration, query, activeQuery)) {
                    return;
                }
                mergePayload(payload);
                settlePhase('deep', true);
            })
            .catch((error) => {
                if (error.name === 'AbortError') {
                    return;
                }
                if (!publicSearchResponseIsCurrent(generation, activeGeneration, query, activeQuery)) {
                    return;
                }
                settlePhase('deep', false);
            });
    };


    /**
     * Run search.
     *
     * Used by browser-side gallery behavior.
     *
     * @return {void} Result value for the caller.
     */
    const runSearch = () => {
        const query = input.value.trim();
        setClearVisibility();
        if (query.length < minLength) {
            activeGeneration += 1;
            activeQuery = '';
            visibleItems = [];
            pendingPhases = new Set();
            completedPhases = new Set();
            failedPhases = new Set();
            abortSearchWork();
            hideResults();
            return;
        }

        abortSearchWork();
        activeGeneration += 1;
        const generation = activeGeneration;
        activeQuery = query;
        visibleItems = [];
        renderedResultKeys = new Set();
        pendingPhases = new Set(['primary', 'media', 'descriptive', 'deep']);
        completedPhases = new Set();
        failedPhases = new Set();
        saveSmartGalleryUrl = '';
        saveSmartGalleryLabel = '';
        root.classList.add('is-loading');
        root.setAttribute('aria-busy', 'true');
        renderStatus(root.getAttribute('data-loading-label') || 'Searching...');

        requestPrimaryPhase(generation, query);
        mediaTimer = window.setTimeout(() => {
            requestMediaPhase(generation, query);
        }, Math.max(0, mediaDelayMs));
        descriptiveTimer = window.setTimeout(() => {
            requestDescriptivePhase(generation, query);
        }, Math.max(0, descriptiveDelayMs));
        deepTimer = window.setTimeout(() => {
            requestDeepPhase(generation, query);
        }, Math.max(0, deepDelayMs));
    };

    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        setClearVisibility();
        timer = window.setTimeout(runSearch, delayMs);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            input.value = '';
            activeGeneration += 1;
            activeQuery = '';
            visibleItems = [];
            pendingPhases = new Set();
            completedPhases = new Set();
            failedPhases = new Set();
            abortSearchWork();
            setClearVisibility();
            hideResults();
        }
    });

    if (clearButton instanceof HTMLButtonElement) {
        clearButton.addEventListener('click', () => {
            input.value = '';
            activeGeneration += 1;
            activeQuery = '';
            visibleItems = [];
            pendingPhases = new Set();
            completedPhases = new Set();
            failedPhases = new Set();
            abortSearchWork();
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
