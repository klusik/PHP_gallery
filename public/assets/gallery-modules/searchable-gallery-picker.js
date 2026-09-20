/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/searchable-gallery-picker.js
 * Module Type: Browser Module
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * Purpose:
 *   Provides a shared searchable gallery destination picker.
 * Responsibilities:
 *   - Keep submitted values in hidden inputs so backend forms remain unchanged
 *   - Debounce searches and retain explicit mouse/touch/keyboard commitment
 *   - Bound DOM work to the current server page and dispose replaced controls
 */

import { GALLERY_PICKER_SEARCH_DEBOUNCE_MS, GALLERY_PICKER_DISPLAY_DEPTH_LIMIT, GALLERY_PICKER_POSITIVE_ID_PATTERN } from './gallery-picker-policy.js?v=20260920-picker-policy-v1';

/**
 * One bounded destination supplied by the authenticated search response.
 * @typedef {Object} GalleryPickerRow
 * @property {string} id Exact positive decimal identifier, never coerced through Number.
 * @property {string} title Plain gallery title, rendered with textContent.
 * @property {string} path Full relative ancestry used to distinguish repeated titles.
 * @property {string} label Complete committed input label including path and identity.
 * @property {number} [depth] Optional visual indentation depth, clamped independently of the path text.
 */

/** Mounted picker owners; removed fragments are disposed by one observer. */
const pickerOwners = new Map();
/** One document observer covers dynamically injected Admin panel fragments. */
let pickerObserver = null;

/**
 * Notify existing form owners about an explicitly changed destination.
 * @param {HTMLInputElement} hiddenInput Canonical submitted ID.
 * @return {void}
 */
function notifyHiddenValueChanged(hiddenInput) {
    hiddenInput.dispatchEvent(new Event('input', {bubbles: true}));
    hiddenInput.dispatchEvent(new Event('change', {bubbles: true}));
}

/**
 * Bind one bounded destination control and its request lifetime.
 * @param {HTMLElement} picker Server-rendered picker root.
 * @return {void}
 */
function setupOneGallerySearchPicker(picker) {
    if (pickerOwners.has(picker)) return;
    const input = picker.querySelector('[data-gallery-search-picker-input]');
    const hiddenInput = picker.querySelector('[data-gallery-search-picker-value]');
    const menu = picker.querySelector('[data-gallery-search-picker-menu]');
    const status = picker.querySelector('[data-gallery-search-picker-status]');
    const moreButton = picker.querySelector('[data-gallery-search-picker-more]');
    const clearButton = picker.querySelector('[data-gallery-search-picker-clear]');
    if (!(input instanceof HTMLInputElement) || !(hiddenInput instanceof HTMLInputElement)
        || !(menu instanceof HTMLElement) || !(status instanceof HTMLElement)) return;

    const lifetime = new AbortController();
    const eventOptions = {signal: lifetime.signal};
    // The server's fixed page budget is rendered once, never a request parameter.
    const pageSize = Number(picker.dataset.pageSize);
    if (!Number.isInteger(pageSize) || pageSize < 1) return;
    const rootOption = menu.querySelector('[data-gallery-search-picker-root]');
    let options = Array.from(menu.querySelectorAll('[data-gallery-search-picker-option]'));
    let activeIndex = -1;
    let generation = 0;
    let timer = 0;
    let pending = null;
    let composing = false;
    let edited = false;
    let query = '';
    let nextCursor = picker.dataset.nextAfterId || '';
    hiddenInput.disabled = input.disabled;
    picker.dataset.gallerySearchPickerBound = '1';

    /**
     * Require explicit root/parent commitment before a parent form can submit.
     * @return {void} Update the visible input's native form-validation state.
     */
    function updateCommitmentValidity() {
        if (rootOption) {
            const help = picker.querySelector('[data-gallery-search-picker-help]');
            input.setCustomValidity(hiddenInput.value === '' ? (help?.textContent || input.placeholder) : '');
        }
    }

    /**
     * Cancel every older request/timer before any selection or query transition.
     * @return {void}
     */
    function invalidate() {
        generation += 1;
        window.clearTimeout(timer);
        timer = 0;
        pending?.abort();
        pending = null;
        menu.removeAttribute('aria-busy');
    }

    /**
     * Open or close only this control without changing its panel or URL.
     * @param {boolean} open Whether choices should remain visible.
     * @return {void}
     */
    function setOpen(open) {
        menu.hidden = !open;
        input.setAttribute('aria-expanded', String(open));
        picker.classList.toggle('is-gallery-search-open', open);
        if (moreButton) moreButton.hidden = !open || nextCursor === '';
        if (!open) input.removeAttribute('aria-activedescendant');
    }

    /**
     * Move the active descendant through the current bounded page.
     * @param {number} index Requested page-relative option index.
     * @return {void}
     */
    function highlight(index) {
        activeIndex = options.length && index >= 0 ? Math.min(index, options.length - 1) : -1;
        options.forEach(
            /** Synchronize option presentation with the current active descendant. @param {HTMLElement} option Current page option. @param {number} optionIndex Page-relative index. @return {void} Updates selection attributes. */
            (option, optionIndex) => {
            option.classList.toggle('is-active', optionIndex === activeIndex);
            option.setAttribute('aria-selected', String(optionIndex === activeIndex));
        });
        if (activeIndex >= 0) input.setAttribute('aria-activedescendant', options[activeIndex].id);
        else input.removeAttribute('aria-activedescendant');
    }

    /**
     * Replace page choices, retaining only the explicit root action as context.
     * @param {GalleryPickerRow[]} rows Validated server-returned physical destinations.
     * @return {void}
     */
    function renderRows(rows) {
        menu.replaceChildren();
        if (rootOption) menu.appendChild(rootOption);
        rows.forEach(
            /** Build one safe result button without interpreting title or path as markup. @param {GalleryPickerRow} row Bounded destination. @param {number} index Page-relative index for accessible identity. @return {void} Appends the result node. */
            (row, index) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.id = input.id + '-option-' + index;
            option.className = 'gallery-search-picker-option';
            option.setAttribute('role', 'option');
            option.dataset.gallerySearchPickerOption = '';
            option.dataset.galleryId = String(row.id);
            option.dataset.galleryLabel = row.label;
            option.style.setProperty('--gallery-picker-depth', String(Math.min(GALLERY_PICKER_DISPLAY_DEPTH_LIMIT, Math.max(0, row.depth || 0))));
            const title = document.createElement('span');
            title.className = 'gallery-search-picker-option-title';
            title.textContent = row.title;
            const path = document.createElement('span');
            path.className = 'gallery-search-picker-option-path';
            path.textContent = '/' + row.path + ' (#' + row.id + ')';
            option.append(title, path);
            menu.appendChild(option);
        });
        options = Array.from(menu.querySelectorAll('[data-gallery-search-picker-option]'));
        highlight(rows.length ? (rootOption ? 1 : 0) : -1);
    }

    /**
     * Fetch one page, rejecting obsolete responses even if abort is ignored.
     * @param {string} nextQuery Literal search text.
     * @param {string} afterId Exclusive page cursor, empty initially.
     * @return {Promise<void>}
     */
    async function search(nextQuery, afterId = '') {
        invalidate();
        const owner = generation;
        query = nextQuery;
        nextCursor = '';
        renderRows([]);
        setOpen(true);
        status.hidden = false;
        status.textContent = picker.dataset.loadingLabel || '';
        menu.setAttribute('aria-busy', 'true');
        const request = new AbortController();
        pending = request;
        try {
            const url = new URL(picker.dataset.searchUrl, document.baseURI);
            if (url.origin !== window.location.origin || !['http:', 'https:'].includes(url.protocol)) throw new Error('Invalid search URL');
            url.searchParams.set('q', nextQuery);
            url.searchParams.set('after_id', afterId || '0');
            url.searchParams.set('selected_id', hiddenInput.value || '0');
            const response = await fetch(url.href, {credentials: 'same-origin', cache: 'no-store',
                headers: {'Accept': 'application/json'}, signal: request.signal});
            if (!response.ok) throw new Error('Search unavailable');
            const data = await response.json();
            if (owner !== generation || !picker.isConnected) return;
            if (data?.ok !== true || !Array.isArray(data.rows) || data.rows.length > pageSize
                || data.rows.some(
                    /** Reject rows without exact ID strings and presentation text. @param {unknown} row Untrusted JSON row; malformed access is caught by the request owner. @return {boolean} Whether this row invalidates the response. */
                    (row) => typeof row.id !== 'string' || !GALLERY_PICKER_POSITIVE_ID_PATTERN.test(row.id)
                    || typeof row.title !== 'string' || typeof row.path !== 'string' || typeof row.label !== 'string')) {
                throw new Error('Invalid search results');
            }
            const next = data.next_after_id;
            if (next !== null && (typeof next !== 'string' || !GALLERY_PICKER_POSITIVE_ID_PATTERN.test(next)
                || BigInt(next) <= BigInt(afterId || '0')
                || !data.rows.length || next !== data.rows[data.rows.length - 1].id)) throw new Error('Invalid cursor');
            nextCursor = next === null ? '' : String(next);
            renderRows(data.rows);
            status.textContent = data.rows.length ? '' : (picker.dataset.emptyLabel || '');
            status.hidden = data.rows.length > 0;
            setOpen(true);
        } catch (error) {
            if (owner !== generation || !picker.isConnected || error.name === 'AbortError') return;
            status.textContent = picker.dataset.errorLabel || '';
            status.hidden = false;
        } finally {
            if (owner === generation) {
                pending = null;
                menu.removeAttribute('aria-busy');
            }
        }
    }

    /**
     * Commit only a currently owned option; typed labels never imply an ID.
     * @param {HTMLElement} option Current result/root button.
     * @return {void}
     */
    function commit(option) {
        if (!options.includes(option)) return;
        invalidate();
        hiddenInput.value = option.dataset.galleryId;
        input.value = option.dataset.galleryLabel;
        picker.dataset.gallerySearchCommittedLabel = input.value;
        picker.classList.remove('is-gallery-search-uncommitted');
        updateCommitmentValidity();
        edited = false;
        setOpen(false);
        status.hidden = true;
        notifyHiddenValueChanged(hiddenInput);
    }

    /**
     * Invalidate results synchronously, then debounce the current input task.
     * @return {void}
     */
    function inputChanged() {
        invalidate();
        edited = true;
        if (hiddenInput.value !== '') {
            hiddenInput.value = '';
            updateCommitmentValidity();
            notifyHiddenValueChanged(hiddenInput);
        }
        delete picker.dataset.gallerySearchCommittedLabel;
        picker.classList.add('is-gallery-search-uncommitted');
        nextCursor = '';
        renderRows([]);
        setOpen(false);
        status.hidden = true;
        if (!composing) timer = window.setTimeout(
            /** Search the latest settled text owned by this input. @return {Promise<void>} Completes the bounded request. */
            () => search(input.value), GALLERY_PICKER_SEARCH_DEBOUNCE_MS);
    }

    input.addEventListener('input', inputChanged, eventOptions);
    input.addEventListener('compositionstart',
        /** Revoke in-flight work while IME text is provisional. @return {void} Enters composing state. */
        () => { composing = true; invalidate(); }, eventOptions);
    input.addEventListener('compositionend',
        /** Resume normal request scheduling after IME commitment. @return {void} Processes the final entered text. */
        () => { composing = false; inputChanged(); }, eventOptions);
    input.addEventListener('focus',
        /** Discover choices without interpreting a committed path as a search query. @return {void} Starts a bounded page request outside composition. */
        () => {
        if (!composing) search(edited ? input.value : '');
    }, eventOptions);
    input.addEventListener('keydown',
        /** Keep keyboard navigation and explicit commitment within the active picker. @param {KeyboardEvent} event Input keystroke. @return {void} Handles only unmodified picker keys. */
        (event) => {
        if (event.isComposing || composing || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return;
        if (event.key === 'Escape') {
            if (!menu.hidden || pending || timer) {
                event.preventDefault();
                event.stopPropagation();
                invalidate();
                setOpen(false);
                status.hidden = true;
            }
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            event.stopPropagation();
            if (menu.hidden) search(edited ? input.value : '');
            else highlight(activeIndex + (event.key === 'ArrowDown' ? 1 : -1));
        } else if (event.key === 'Enter' && !menu.hidden) {
            event.preventDefault();
            event.stopPropagation();
            if (activeIndex >= 0) commit(options[activeIndex]);
        } else if (event.key === 'Tab') {
            invalidate();
            setOpen(false);
        }
    }, eventOptions);
    menu.addEventListener('pointerdown',
        /** Retain input focus while a result pointer action is pending. @param {PointerEvent} event Result-menu pointer intent. @return {void} Prevents only option focus transfer. */
        (event) => {
        if (event.target.closest('[data-gallery-search-picker-option]')) event.preventDefault();
    }, eventOptions);
    menu.addEventListener('click',
        /** Commit a result only when it still belongs to this menu. @param {MouseEvent} event Delegated option click. @return {void} Commits the owned result or ignores it. */
        (event) => {
        const option = event.target.closest('[data-gallery-search-picker-option]');
        if (option && menu.contains(option)) {
            event.preventDefault();
            commit(option);
        }
    }, eventOptions);
    moreButton?.addEventListener('click',
        /** Replace the current page using its validated continuation cursor. @param {MouseEvent} event Next-page click. @return {void} Starts one bounded continuation. */
        (event) => {
        event.preventDefault();
        if (nextCursor) search(query, nextCursor);
    }, eventOptions);
    clearButton?.addEventListener('click',
        /** Revoke the existing commitment and refocus the cleared input. @return {void} Leaves root selection explicit. */
        () => {
        input.value = '';
        inputChanged();
        input.focus({preventScroll: true});
    }, eventOptions);
    picker.addEventListener('focusout',
        /** Cancel pending work when focus leaves this complete control. @param {FocusEvent} event Focus transition. @return {void} Hides results only for an external destination. */
        (event) => {
        if (!picker.contains(event.relatedTarget)) {
            invalidate();
            setOpen(false);
            status.hidden = true;
        }
    }, eventOptions);
    document.addEventListener('pointerdown',
        /** Dismiss this picker's work on an outside pointer interaction. @param {PointerEvent} event Document pointer intent. @return {void} Leaves other controls and the panel untouched. */
        (event) => {
        if (!picker.contains(event.target)) {
            invalidate();
            setOpen(false);
            status.hidden = true;
        }
    }, eventOptions);
    pickerOwners.set(picker,
        /** Dispose request, debounce and event ownership when this fragment detaches. @return {void} Allows a later fragment to bind independently. */
        () => {
        invalidate();
        lifetime.abort();
        delete picker.dataset.gallerySearchPickerBound;
    });
    if (hiddenInput.value !== '') picker.dataset.gallerySearchCommittedLabel = input.value;
    else picker.classList.add('is-gallery-search-uncommitted');
    updateCommitmentValidity();
    highlight(-1);
    setOpen(false);
}

/**
 * Bind current and future picker fragments, disposing detached request owners.
 * @return {void}
 */
export function setupGallerySearchPickers() {
    document.querySelectorAll('[data-gallery-search-picker]').forEach(setupOneGallerySearchPicker);
    if (pickerObserver || !document.body) return;
    pickerObserver = new MutationObserver(
        /** Dispose detached owners before binding newly inserted picker fragments. @param {MutationRecord[]} records Document child-list changes. @return {void} Maintains one owner per connected control. */
        (records) => {
        for (const [picker, dispose] of pickerOwners) {
            if (!picker.isConnected) {
                dispose();
                pickerOwners.delete(picker);
            }
        }
        records.forEach(
            /** Visit inserted nodes from this child-list mutation. @param {MutationRecord} record Observed insertion record. @return {void} Checks only its added subtree. */
            (record) => record.addedNodes.forEach(
                /** Bind picker roots and descendants in one inserted HTML subtree. @param {Node} node Newly inserted node. @return {void} Ignores non-HTML nodes. */
                (node) => {
            if (!(node instanceof HTMLElement)) return;
            if (node.matches('[data-gallery-search-picker]')) setupOneGallerySearchPicker(node);
            node.querySelectorAll('[data-gallery-search-picker]').forEach(setupOneGallerySearchPicker);
        }));
    });
    pickerObserver.observe(document.body, {childList: true, subtree: true});
}
