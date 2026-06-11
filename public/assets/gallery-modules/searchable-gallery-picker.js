/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/searchable-gallery-picker.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides a shared searchable gallery destination picker.
 *
 * Responsibilities:
 *   - Replace long gallery destination selects with a text search workflow
 *   - Keep submitted values in hidden inputs so backend forms remain unchanged
 *   - Debounce gallery filtering after typing to avoid noisy DOM updates
 *   - Support mouse, touch, and keyboard confirmation of highlighted results
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
 *   2026-05-19
 */

/**
 * Normalize user-entered search text for stable case-insensitive matching.
 *
 * @param {string} value Raw text from the input or option metadata.
 * @return {string} Lowercase normalized text.
 */
function normalizeSearchText(value) {
    return String(value || '')
        .toLocaleLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[\\/_-]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

/**
 * Returns a lightweight relevance score for one gallery option.
 *
 * Smaller numbers are better. The scoring keeps exact and prefix title matches
 * above path-only matches, which mirrors how users usually search galleries by
 * visible name first and folder path second.
 *
 * @param {HTMLElement} option Gallery option button.
 * @param {string} query Normalized user query.
 * @return {number} Relevance score, or 9999 when the option does not match.
 */
function galleryOptionScore(option, query) {
    const title = normalizeSearchText(option.dataset.galleryTitle || '');
    const path = normalizeSearchText(option.dataset.galleryPath || '');
    const search = normalizeSearchText(option.dataset.gallerySearch || '');
    if (query === '') {
        return 50;
    }
    if (title === query) {
        return 0;
    }
    if (title.startsWith(query)) {
        return 10;
    }
    if (title.includes(query)) {
        return 20;
    }
    if (path.startsWith(query)) {
        return 30;
    }
    if (path.includes(query) || search.includes(query)) {
        return 40;
    }
    return 9999;
}

/**
 * Dispatch input and change events from the hidden submitted value.
 *
 * Existing modules already listen to normal form events. Emitting both events
 * keeps this picker compatible with those flows without special-case callbacks.
 *
 * @param {HTMLInputElement} hiddenInput Hidden submitted gallery ID field.
 */
function notifyHiddenValueChanged(hiddenInput) {
    hiddenInput.dispatchEvent(new Event('input', {bubbles: true}));
    hiddenInput.dispatchEvent(new Event('change', {bubbles: true}));
}

/**
 * Enables one searchable gallery picker widget.
 *
 * @param {HTMLElement} picker Server-rendered picker root.
 */
function setupOneGallerySearchPicker(picker) {
    if (picker.dataset.gallerySearchPickerBound === '1') {
        return;
    }
    picker.dataset.gallerySearchPickerBound = '1';

    // input stores the user-facing search and committed label text.
    const input = picker.querySelector('[data-gallery-search-picker-input]');
    // hiddenInput stores the submitted gallery ID used by existing PHP handlers.
    const hiddenInput = picker.querySelector('[data-gallery-search-picker-value]');
    // menu stores the listbox that contains result option buttons.
    const menu = picker.querySelector('[data-gallery-search-picker-menu]');
    // emptyMessage stores the no-results text.
    const emptyMessage = picker.querySelector('[data-gallery-search-picker-empty]');
    // clearButton stores the visible reset action.
    const clearButton = picker.querySelector('[data-gallery-search-picker-clear]');
    // options stores every gallery option button rendered by PHP.
    const options = Array.from(picker.querySelectorAll('[data-gallery-search-picker-option]'))
        .filter((option) => option instanceof HTMLElement);
    if (!(input instanceof HTMLInputElement) || !(hiddenInput instanceof HTMLInputElement) || !(menu instanceof HTMLElement)) {
        return;
    }

    // searchDelay stores the debounce duration requested by the UI specification.
    const searchDelay = Math.max(0, Number.parseInt(picker.dataset.searchDelay || '200', 10) || 200);
    // visibleOptions stores the currently matching options in ranked order.
    let visibleOptions = [];
    // activeIndex stores the highlighted visible option index.
    let activeIndex = -1;
    // filterTimer stores the pending debounce timer after typing.
    let filterTimer = 0;
    // committedLabel stores the last label that matches the hidden submitted ID.
    let committedLabel = input.value.trim();

        /**
     * Opens or closes the result menu.
     *
     * @param {boolean} open Whether the menu should be visible.
     */
    function setMenuOpen(open) {
        menu.hidden = !open;
        input.setAttribute('aria-expanded', open ? 'true' : 'false');
        picker.classList.toggle('is-gallery-search-open', open);
    }

        /**
     * Updates the active descendant used by keyboard navigation.
     *
     * @param {number} nextIndex New highlighted option index.
     */
    function setActiveIndex(nextIndex) {
        activeIndex = visibleOptions.length === 0 ? -1 : Math.max(0, Math.min(nextIndex, visibleOptions.length - 1));
        options.forEach((option) => {
            option.classList.remove('is-active');
            option.setAttribute('aria-selected', 'false');
        });
        if (activeIndex >= 0 && visibleOptions[activeIndex]) {
            const activeOption = visibleOptions[activeIndex];
            activeOption.classList.add('is-active');
            activeOption.setAttribute('aria-selected', 'true');
            input.setAttribute('aria-activedescendant', activeOption.id || '');
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

        /**
     * Commits one option into the hidden submitted value.
     *
     * @param {HTMLElement|null} option Option button to commit.
     */
    function commitOption(option) {
        if (!(option instanceof HTMLElement)) {
            return;
        }
        const galleryId = option.dataset.galleryId || '';
        const label = option.dataset.galleryLabel || option.textContent?.trim() || '';
        if (galleryId === '') {
            return;
        }
        hiddenInput.value = galleryId;
        input.value = label;
        committedLabel = label;
        picker.dataset.gallerySearchCommittedLabel = label;
        picker.classList.remove('is-gallery-search-uncommitted');
        setMenuOpen(false);
        notifyHiddenValueChanged(hiddenInput);
    }

        /**
     * Clears the submitted gallery value and leaves the user ready to search.
     */
    function clearSelection() {
        hiddenInput.value = '';
        input.value = '';
        committedLabel = '';
        picker.classList.add('is-gallery-search-uncommitted');
        notifyHiddenValueChanged(hiddenInput);
        applyFilter(true);
        input.focus({preventScroll: true});
    }

        /**
     * Applies the current query to all option buttons.
     *
     * @param {boolean} openAfterFiltering Whether matching results should be shown.
     */
    function applyFilter(openAfterFiltering) {
        const query = normalizeSearchText(input.value);
        visibleOptions = options
            .map((option, index) => ({option, index, score: galleryOptionScore(option, query)}))
            .filter((entry) => entry.score < 9999)
            .sort((left, right) => left.score - right.score || left.index - right.index)
            .slice(0, 12)
            .map((entry) => entry.option);

        const visibleSet = new Set(visibleOptions);

        // Move matching options to the top in ranked order. This keeps the menu
        // visually dynamic even when the browser keeps focus inside the input.
        visibleOptions.forEach((option) => {
            menu.appendChild(option);
        });

        options.forEach((option) => {
            const isVisible = visibleSet.has(option);
            option.hidden = !isVisible;
            option.style.display = isVisible ? '' : 'none';
        });
        if (emptyMessage instanceof HTMLElement) {
            emptyMessage.hidden = visibleOptions.length > 0;
            emptyMessage.style.display = visibleOptions.length > 0 ? 'none' : '';
            menu.appendChild(emptyMessage);
        }
        setActiveIndex(0);
        setMenuOpen(openAfterFiltering && (visibleOptions.length > 0 || query !== ''));
    }

        /**
     * Schedules filtering after the configured debounce delay.
     */
    function scheduleFilter() {
        window.clearTimeout(filterTimer);
        filterTimer = window.setTimeout(() => applyFilter(true), searchDelay);
    }

        /**
     * Applies filtering immediately after navigation and composition-safe input.
     *
     * The debounce is still used for normal typing, but this helper lets paste,
     * cut, and rapid correction update the visible result list predictably.
     */
    function applyFilterSoon() {
        window.clearTimeout(filterTimer);
        window.requestAnimationFrame(() => applyFilter(true));
    }

    input.addEventListener('focus', () => {
        applyFilter(true);
    });

    input.addEventListener('input', () => {
        if (input.value.trim() !== committedLabel) {
            hiddenInput.value = '';
            picker.classList.add('is-gallery-search-uncommitted');
            notifyHiddenValueChanged(hiddenInput);
        }
        scheduleFilter();
    });

    input.addEventListener('search', applyFilterSoon);
    input.addEventListener('paste', applyFilterSoon);
    input.addEventListener('cut', applyFilterSoon);

    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            if (menu.hidden) {
                applyFilter(true);
            } else {
                setActiveIndex(activeIndex + 1);
            }
            return;
        }
        if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveIndex(activeIndex - 1);
            return;
        }
        if (event.key === 'Enter') {
            if (!menu.hidden && activeIndex >= 0 && visibleOptions[activeIndex]) {
                event.preventDefault();
                commitOption(visibleOptions[activeIndex]);
            }
            return;
        }
        if (event.key === 'Escape') {
            setMenuOpen(false);
        }
    });

    options.forEach((option) => {
        option.addEventListener('mousedown', (event) => {
            event.preventDefault();
        });
        option.addEventListener('click', () => {
            commitOption(option);
            input.focus({preventScroll: true});
        });
    });

    if (clearButton instanceof HTMLButtonElement) {
        clearButton.addEventListener('click', clearSelection);
    }

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (target instanceof Node && picker.contains(target)) {
            return;
        }
        setMenuOpen(false);
    });

    input.addEventListener('blur', () => {
        window.setTimeout(() => setMenuOpen(false), 120);
    });

    if (hiddenInput.value === '' && input.value.trim() !== '') {
        picker.classList.add('is-gallery-search-uncommitted');
    }
    applyFilter(false);
}

/**
 * Enables all searchable gallery picker widgets on the current page.
 */
export function setupGallerySearchPickers() {
    document.querySelectorAll('[data-gallery-search-picker]').forEach((picker) => {
        if (picker instanceof HTMLElement) {
            setupOneGallerySearchPicker(picker);
        }
    });
}
