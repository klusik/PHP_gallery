/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-music.js
 * Module Type: Browser Module
 * Purpose: Keep Music Manager editing, search, and mutations in place.
 * Responsibilities: Delegate title editing, live search, mutations, and refreshes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {completeAdminMutation} from './admin-mutation-completion.js?v=20261016-three-state-covers-v1';
import {setupAdminTabsInRoot} from './admin-tabs.js?v=20261021-upload-media-v1';
import {setupAdminMusicAlbumOrdering} from './admin-music-album-order.js?v=20261020-album-order-v4';

/**
 * Bound one mutation and its owned fragment refresh.
 * @var {number}
 * Units: milliseconds. Scope: one delegated Music Manager submit lifecycle.
 * Consumers: setupAdminMusic().
 * Rationale: a stalled write or refresh must terminate with visible state and
 * cannot leave the compact manager busy indefinitely. Twenty seconds permits
 * one bounded import batch plus fragment rendering on ordinary hosting.
 * Value: 20000 milliseconds.
 */
const ADMIN_MUSIC_REQUEST_TIMEOUT_MS = 20000;

/**
 * Wait briefly after the latest keystroke before requesting filtered rows.
 * Type: number. Units: milliseconds. Scope: one Music Manager live-search burst.
 * Consumers: setupAdminMusic().
 * Rationale: allow normal typing to settle while superseding older reads as
 * soon as a new input arrives. Value: 250 milliseconds.
 */
const ADMIN_MUSIC_SEARCH_DEBOUNCE_MS = 250;

/**
 * Bound one catalog fragment read independently of mutation requests.
 * Type: number. Units: milliseconds. Scope: one Music Manager search request.
 * Consumers: requestAdminMusicSearch().
 * Rationale: a slow filter must not leave the latest query unresolved forever.
 * Value: 10000 milliseconds.
 */
const ADMIN_MUSIC_SEARCH_TIMEOUT_MS = 10000;

/** Keep a pointer-open title editor close to its trigger. Type: number. @type {number} Units: CSS pixels. Scope: title editor placement. Consumers: positionAdminMusicTitleEditor(). Rationale: separate the popup edge from the click target without obscuring its label; Value: 8. */
const ADMIN_MUSIC_TITLE_EDITOR_GUTTER_PX = 8;
/** Keep title editor bounds inside the visible browser area. Type: number. @type {number} Units: CSS pixels. Scope: title editor viewport clamp. Consumers: positionAdminMusicTitleEditor(). Rationale: retain a visible edge for popups at viewport boundaries; Value: 8. */
const ADMIN_MUSIC_TITLE_EDITOR_VIEWPORT_MARGIN_PX = 8;
/** Keep a short title editor wide enough for its label and save control. Type: number. @type {number} Units: rem. Scope: title editor content sizing. Consumers: sizeAdminMusicTitleEditor(). Rationale: the popup should feel usable before text growth reaches its viewport bound; Value: 20rem. */
const ADMIN_MUSIC_TITLE_EDITOR_MIN_WIDTH_REM = 20;
/** Bound expanded title popovers to the production stylesheet cap. Type: number. @type {number} Units: rem. Scope: title editor width variable. Consumers: sizeAdminMusicTitleEditor(). Rationale: long names must wrap inside the viewport without covering the whole workspace; Value: 40rem. */
const ADMIN_MUSIC_TITLE_EDITOR_MAX_WIDTH_REM = 40;
/** Preserve a visible edge around row disclosure popups. Type: number. Units: CSS pixels. Scope: Music Manager table disclosure placement. Consumers: positionAdminMusicRowDisclosure(). Rationale: keep file and usage details on-screen even when opened from the final narrow table row; Value: 8. */
const ADMIN_MUSIC_ROW_POPUP_VIEWPORT_MARGIN_PX = 8;

let adminMusicSearchGeneration = 0;
/** @type {number|null} Timer for the current search debounce window. */
let adminMusicSearchTimer = null;
/** @type {AbortController|null} Abort owner for the latest fragment request. */
let adminMusicSearchController = null;
/**
 * Search editor state captured before a fragment request.
 * @typedef {{query:string,selectionStart:number|null,selectionEnd:number|null,selectionDirection:'forward'|'backward'|'none'|null,restoreFocus:boolean}} AdminMusicSearchState
 */
/**
 * A query queued while an owned mutation refreshes the manager root.
 * @typedef {{generation:number,state:AdminMusicSearchState}} AdminMusicPendingSearch
 */
/**
 * Viewport point used to place a title editor across text resizes.
 * @typedef {{kind:'pointer'|'keyboard',x:number,y:number}} AdminMusicTitleEditorAnchor
 */
/** @type {AdminMusicPendingSearch|null} Latest current query waiting for a write to finish. */
let pendingAdminMusicSearch = null;
/** @type {{disclosure:HTMLDetailsElement,form:HTMLFormElement,popup:HTMLElement,titleField:HTMLTextAreaElement,trigger:HTMLElement,anchor:AdminMusicTitleEditorAnchor}|null} The active song popover, mutation form and placement source; trigger is a native summary. */
let activeAdminMusicTitleEditor = null;

/**
 * Invalidate every earlier live-search request before waiting for debounce.
 * @returns {number} Generation assigned to the next permitted search response.
 */
function invalidateAdminMusicSearch() {
    adminMusicSearchGeneration += 1;
    if (adminMusicSearchTimer !== null) {
        clearTimeout(adminMusicSearchTimer);
        adminMusicSearchTimer = null;
    }
    adminMusicSearchController?.abort();
    adminMusicSearchController = null;
    return adminMusicSearchGeneration;
}

/** Cancel pending reads when the user starts editing catalog metadata.
 * @returns {void} Prevents a stale fragment from replacing an active editor.
 */
function invalidateAdminMusicSearchForEditor() {
    pendingAdminMusicSearch = null;
    invalidateAdminMusicSearch();
}

/**
 * Build a same-origin GET URL from the production search form and current query.
 * @param {HTMLFormElement} form Prepared Music Manager GET form.
 * @param {string} query Latest visible search text.
 * @returns {URL} Search URL retaining prepared album and sort state at offset zero.
 */
function adminMusicSearchURL(form, query) {
    const action = form.getAttribute('action') || window.location.href;
    const url = new URL(action, window.location.href);
    if (url.origin !== window.location.origin) {
        throw new Error('Music search must use the current gallery origin.');
    }
    const formData = new FormData(form);
    formData.set('q', query);
    const root = form.closest('[data-admin-music]');
    const selectedTab = root?.querySelector(
        '.admin-music-tabs [role="tab"][aria-selected="true"]',
    );
    if (selectedTab instanceof HTMLElement) {
        formData.set('tab', selectedTab.dataset.adminTabTarget
            === 'admin-music-tab-albums' ? 'albums' : 'songs');
        const tabUrl = selectedTab instanceof HTMLAnchorElement
            ? new URL(selectedTab.href, window.location.href) : null;
        if (tabUrl) url.hash = tabUrl.hash;
    }
    const formKeys = new Set(Array.from(formData.keys()));
    for (const key of new Set(url.searchParams.keys())) {
        if (formKeys.has(key) || key === 'offset') {
            url.searchParams.delete(key);
        }
    }
    for (const [key, value] of formData.entries()) {
        if (typeof value === 'string') url.searchParams.append(key, value);
    }
    url.searchParams.delete('fragment');
    url.searchParams.set('offset', '0');
    return url;
}

/** Keep the shared tab module's bound navigation and panel nodes across a fragment refresh.
 * @param {HTMLElement} currentRoot Live manager owning the already-bound tab group.
 * @param {HTMLElement} replacement Newly rendered canonical manager fragment.
 * @returns {string} Selected panel ID retained for hidden mutation return fields.
 */
function preserveAdminMusicTabBindings(currentRoot, replacement) {
    const currentNavigation = currentRoot.querySelector('.admin-music-tabs');
    const nextNavigation = replacement.querySelector('.admin-music-tabs');
    if (!(currentNavigation instanceof HTMLElement)
        || !(nextNavigation instanceof HTMLElement)) {
        throw new Error('Music fragment is missing its shared tab navigation.');
    }
    const selectedTab = currentNavigation.querySelector(
        '[role="tab"][aria-selected="true"][data-admin-tab-target]',
    );
    const selectedPanelId = selectedTab instanceof HTMLElement
        ? selectedTab.dataset.adminTabTarget || '' : '';
    if (!selectedPanelId) {
        throw new Error('Music tab selection is missing from the current fragment.');
    }
    const oldTabs = Array.from(currentNavigation.querySelectorAll(
        '[role="tab"][data-admin-tab-target]',
    ));
    const newTabs = Array.from(nextNavigation.querySelectorAll(
        '[role="tab"][data-admin-tab-target]',
    ));
    for (const oldTab of oldTabs) {
        const matchingTab = newTabs.find(tab =>
            tab.dataset.adminTabTarget === oldTab.dataset.adminTabTarget,
        );
        if (!(matchingTab instanceof HTMLAnchorElement)
            || !(oldTab instanceof HTMLAnchorElement)) {
            throw new Error('Music fragment tab links do not match their bound group.');
        }
        oldTab.href = matchingTab.href;
        const isSelected = oldTab.dataset.adminTabTarget === selectedPanelId;
        oldTab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        oldTab.setAttribute('tabindex', isSelected ? '0' : '-1');
        oldTab.classList.toggle('is-active', isSelected);
    }
    /** @type {Array<{currentPanel:HTMLElement,nextPanel:HTMLElement}>} Bound tab nodes retained for their existing shared listeners. */
    const oldPanels = [];
    for (const panelId of ['admin-music-tab-songs', 'admin-music-tab-albums']) {
        const currentPanel = currentRoot.querySelector(
            `[data-admin-tab-panel]#${panelId}`,
        );
        const nextPanel = replacement.querySelector(
            `[data-admin-tab-panel]#${panelId}`,
        );
        if (!(currentPanel instanceof HTMLElement)
            || !(nextPanel instanceof HTMLElement)) {
            throw new Error('Music fragment is missing a registered tab panel.');
        }
        // Move the prepared nodes so captured input/focus references remain live.
        currentPanel.replaceChildren(...Array.from(nextPanel.childNodes));
        currentPanel.hidden = panelId !== selectedPanelId;
        oldPanels.push({currentPanel, nextPanel});
    }
    nextNavigation.replaceWith(currentNavigation);
    for (const {currentPanel, nextPanel} of oldPanels) {
        nextPanel.replaceWith(currentPanel);
    }
    replacement.querySelectorAll('input[type="hidden"][name="return_tab"]')
        .forEach(/** Keep every newly rendered form aligned with the preserved tab. @param {Element} input Hidden mutation return-tab field. @returns {void} Updates the server-return destination. */ input => {
            if (input instanceof HTMLInputElement) {
                input.value = selectedPanelId;
            }
        });
    return selectedPanelId;
}

/** Keep the survivor choices exactly aligned with checked catalog rows.
 * @param {ParentNode} container Manager root or document containing checkboxes.
 * @returns {void} Rebuilds the survivor select and enables merge when valid.
 */
function syncAdminMusicMergeSelection(container) {
    const form = container.querySelector('#admin-music-merge');
    const root = form?.closest('[data-admin-music]');
    const select = form?.querySelector('select[name="song_id"]');
    const submit = form?.querySelector('button[type="submit"]');
    if (!(form instanceof HTMLFormElement)
        || !(root instanceof HTMLElement)
        || !(select instanceof HTMLSelectElement)
        || !(submit instanceof HTMLButtonElement)) return;
    const requiredSelection = Number.parseInt(form.dataset.mergeMinimum || '', 10);
    if (!Number.isInteger(requiredSelection) || requiredSelection < 1) {
        select.replaceChildren();
        select.disabled = true;
        submit.disabled = true;
        return;
    }
    const selectedRows = Array.from(root.querySelectorAll(
        'input[name="song_ids[]"]:checked',
    )).map(/** Describe one checked song in its rendered row. @param {HTMLInputElement} input Checked catalog song identity. @returns {{id:string,label:string}|null} Prepared survivor option or null for malformed rows. */ input => {
        const id = input.value;
        const label = input.closest('tr')?.querySelector(
            '.admin-music-select span',
        )?.textContent?.trim() || '';
        return /^\d+$/.test(id) && label !== '' ? {id, label} : null;
    }).filter(/** Keep only fully described checked songs. @param {{id:string,label:string}|null} song Prepared selected row. @returns {song is {id:string,label:string}} True when safe to expose as a survivor. */ song => song !== null);
    const previousValue = select.value;
    select.replaceChildren(...selectedRows.map(/** Create one option for an eligible survivor. @param {{id:string,label:string}} song Checked song row. @returns {HTMLOptionElement} Matching option node. */ song => {
        const option = document.createElement('option');
        option.value = song.id;
        option.textContent = song.label;
        return option;
    }));
    const previousIsSelected = selectedRows.some(
        song => song.id === previousValue,
    );
    if (selectedRows.length > 0) {
        select.value = previousIsSelected
            ? previousValue : selectedRows[0].id;
    }
    const canMerge = selectedRows.length >= requiredSelection
        && root.dataset.busy !== '1' && root.dataset.stale !== '1';
    select.disabled = !canMerge;
    submit.disabled = !canMerge;
}

/**
 * Fetch and conditionally replace only the current Music Manager fragment.
 * @param {HTMLFormElement} form Current production search form.
 * @param {AdminMusicSearchState} state Query and editor state captured before the read.
 * @param {number} generation Request generation that must still be current.
 * @returns {Promise<void>} Completes after a current response or reports its read error.
 */
async function requestAdminMusicSearch(form, state, generation) {
    const root = form.closest('[data-admin-music]');
    if (!root || root.dataset.busy === '1' || root.dataset.stale === '1'
        || generation !== adminMusicSearchGeneration) {
        if (root?.dataset.busy === '1' && generation === adminMusicSearchGeneration) {
            pendingAdminMusicSearch = {generation, state};
        }
        return;
    }
    const requestUrl = adminMusicSearchURL(form, state.query);
    const fetchUrl = new URL(requestUrl.toString());
    fetchUrl.searchParams.set('fragment', '1');
    const controller = new AbortController();
    adminMusicSearchController = controller;
    let timedOut = false;
    const timeout = setTimeout(
        /** Abort a live search that exceeds its own bounded read window. @returns {void} Marks and aborts this request. */
        () => {
            timedOut = true;
            controller.abort();
        },
        ADMIN_MUSIC_SEARCH_TIMEOUT_MS,
    );
    try {
        const response = await fetch(fetchUrl.toString(), {
            credentials: 'same-origin',
            headers: {'Accept': 'text/html'},
            signal: controller.signal,
        });
        if (!response.ok) throw new Error('Music search request failed.');
        const markup = await response.text();
        if (generation !== adminMusicSearchGeneration
            || !root.isConnected
            || document.querySelector('[data-admin-music]') !== root
            || root.dataset.busy === '1'
            || root.dataset.stale === '1') return;
        const template = document.createElement('template');
        template.innerHTML = markup;
        const replacement = template.content.querySelector('[data-admin-music]');
        const replacementInput = replacement?.querySelector(
            'form.admin-music-search input[name="q"]',
        );
        if (!(replacement instanceof HTMLElement)
            || !(replacementInput instanceof HTMLInputElement)) {
            throw new Error('Music search response did not contain its manager form.');
        }
        replacement.querySelectorAll('.admin-music-notice')
            .forEach(/** Remove stale response notices before retaining current completion feedback. @param {Element} notice Existing server-rendered message. @returns {void} Avoids duplicating a mutation notice. */ notice => notice.remove());
        root.querySelectorAll('.admin-music-notice')
            .forEach(/** Keep a completed mutation message visible across a live filter. @param {Element} notice Current in-place completion message. @returns {void} Copies one message into the replacement root. */ notice => replacement.prepend(notice.cloneNode(true)));
        replacementInput.value = state.query;
        if (activeAdminMusicTitleEditor
            && root.contains(activeAdminMusicTitleEditor.popup)) {
            closeAdminMusicTitleEditor();
        }
        preserveAdminMusicTabBindings(root, replacement);
        root.replaceWith(replacement);
        setupAdminTabsInRoot(replacement);
        setupAdminMusicAlbumOrdering();
        syncAdminMusicMergeSelection(replacement);
        window.history.replaceState(
            window.history.state,
            '',
            requestUrl.toString(),
        );
        if (state.restoreFocus) {
            replacementInput.focus();
            if (state.selectionStart !== null && state.selectionEnd !== null) {
                replacementInput.setSelectionRange(
                    state.selectionStart,
                    state.selectionEnd,
                    state.selectionDirection || 'none',
                );
            }
        }
    } catch (error) {
        if (generation !== adminMusicSearchGeneration
            || controller.signal.aborted && !timedOut
            || !root.isConnected
            || document.querySelector('[data-admin-music]') !== root) return;
        let status = root.querySelector('[data-admin-music-search-status]');
        if (!status) {
            status = document.createElement('p');
            status.dataset.adminMusicSearchStatus = '1';
            status.setAttribute('role', 'alert');
            status.setAttribute('aria-live', 'polite');
            form.insertAdjacentElement('afterend', status);
        }
        status.textContent = timedOut
            ? String(root.dataset.searchTimeoutError || root.dataset.searchError || root.dataset.error || '')
            : String(root.dataset.searchError || (error instanceof Error
                ? error.message : root.dataset.error || ''));
    } finally {
        clearTimeout(timeout);
        if (adminMusicSearchController === controller) {
            adminMusicSearchController = null;
        }
    }
}

/** Close the active title disclosure and remove its pointer-specific placement.
 * @returns {void} Restores the editor to its native row-local position.
 */
function closeAdminMusicTitleEditor() {
    const active = activeAdminMusicTitleEditor;
    activeAdminMusicTitleEditor = null;
    if (!active) return;
    active.disclosure.open = false;
    active.popup.classList.remove('admin-music-title-pointer-editor');
    active.popup.style.removeProperty('left');
    active.popup.style.removeProperty('top');
    active.popup.style.removeProperty('right');
    active.popup.style.removeProperty('bottom');
    active.popup.style.removeProperty('--music-title-editor-width');
    active.titleField.style.removeProperty('height');
}

/** Grow the title popup from its current line length before viewport clamping.
 * @param {HTMLTextAreaElement} titleField One-line title editor using soft wrapping.
 * @returns {void} Sets a character-based width that CSS bounds to the viewport.
 */
function sizeAdminMusicTitleEditor(titleField) {
    const active = activeAdminMusicTitleEditor;
    if (!active || active.form !== titleField.form) return;
    const longestLine = titleField.value.length;
    active.popup.style.setProperty(
        '--music-title-editor-width',
        `min(${ADMIN_MUSIC_TITLE_EDITOR_MAX_WIDTH_REM}rem, max(${ADMIN_MUSIC_TITLE_EDITOR_MIN_WIDTH_REM}rem, ${Math.max(titleField.cols, longestLine + 2)}ch))`,
    );
    titleField.style.height = 'auto';
    const maximumHeight = Number.parseFloat(
        getComputedStyle(titleField).maxHeight,
    );
    const borderHeight = titleField.offsetHeight - titleField.clientHeight;
    const desiredHeight = Number.isFinite(maximumHeight)
        ? Math.min(titleField.scrollHeight + borderHeight, maximumHeight)
        : titleField.scrollHeight + borderHeight;
    titleField.style.height = `${desiredHeight}px`;
    positionAdminMusicTitleEditor(
        active.popup,
        active.trigger,
        active.anchor,
    );
}

/** Place the open editor near a pointer or keyboard-triggered title button.
 * @param {HTMLElement} popup Row-local title popover container.
 * @param {HTMLElement} trigger Native title disclosure summary.
 * @param {AdminMusicTitleEditorAnchor} anchor Pointer or keyboard position captured at open.
 * @returns {void} Applies bounded offsets relative to the editor's owning cell.
 */
function positionAdminMusicTitleEditor(popup, trigger, anchor) {
    const cell = popup.closest('td');
    if (!(cell instanceof HTMLTableCellElement)) return;
    const cellBounds = cell.getBoundingClientRect();
    const triggerBounds = trigger.getBoundingClientRect();
    const desiredLeft = (anchor.kind === 'pointer' ? anchor.x : triggerBounds.left)
        + ADMIN_MUSIC_TITLE_EDITOR_GUTTER_PX;
    const desiredTop = (anchor.kind === 'pointer' ? anchor.y : triggerBounds.bottom)
        + ADMIN_MUSIC_TITLE_EDITOR_GUTTER_PX;
    popup.classList.add('admin-music-title-pointer-editor');
    popup.style.right = 'auto';
    popup.style.bottom = 'auto';
    popup.style.left = `${desiredLeft - cellBounds.left}px`;
    popup.style.top = `${desiredTop - cellBounds.top}px`;
    const bounds = popup.getBoundingClientRect();
    const maxLeft = Math.max(
        ADMIN_MUSIC_TITLE_EDITOR_VIEWPORT_MARGIN_PX,
        window.innerWidth - bounds.width
            - ADMIN_MUSIC_TITLE_EDITOR_VIEWPORT_MARGIN_PX,
    );
    const maxTop = Math.max(
        ADMIN_MUSIC_TITLE_EDITOR_VIEWPORT_MARGIN_PX,
        window.innerHeight - bounds.height
            - ADMIN_MUSIC_TITLE_EDITOR_VIEWPORT_MARGIN_PX,
    );
    const clampedLeft = Math.min(
        maxLeft,
        Math.max(ADMIN_MUSIC_TITLE_EDITOR_VIEWPORT_MARGIN_PX, desiredLeft),
    );
    const clampedTop = Math.min(
        maxTop,
        Math.max(ADMIN_MUSIC_TITLE_EDITOR_VIEWPORT_MARGIN_PX, desiredTop),
    );
    popup.style.left = `${clampedLeft - cellBounds.left}px`;
    popup.style.top = `${clampedTop - cellBounds.top}px`;
}

/** Place an opened row popup within the viewport before its first paint.
 * @param {HTMLDetailsElement} disclosure Open song-row disclosure.
 * @returns {void} Places its list or form using animation-independent dimensions.
 */
function positionAdminMusicRowDisclosure(disclosure) {
    const popup = disclosure.querySelector(':scope > ul, :scope > form');
    const summary = disclosure.querySelector(':scope > summary');
    if (!(popup instanceof HTMLElement)
        || !(summary instanceof HTMLElement)
        || !disclosure.open) return;
    if (!disclosure.isConnected || !disclosure.open) return;
    const cell = disclosure.closest('td');
    if (!(cell instanceof HTMLTableCellElement)) return;
    const cellBounds = cell.getBoundingClientRect();
    const summaryBounds = summary.getBoundingClientRect();
    popup.style.margin = '0';
    const popupBounds = popup.getBoundingClientRect();
    const margin = ADMIN_MUSIC_ROW_POPUP_VIEWPORT_MARGIN_PX;
    const maxLeft = Math.max(margin, window.innerWidth - popupBounds.width - margin);
    const left = Math.min(maxLeft, Math.max(margin, popupBounds.left));
    const maxTop = Math.max(margin, window.innerHeight - popupBounds.height - margin);
    const belowTop = summaryBounds.bottom;
    const preferredTop = belowTop + popupBounds.height + margin <= window.innerHeight - margin
        ? belowTop
        : summaryBounds.top - popupBounds.height;
    const top = Math.min(maxTop, Math.max(margin, preferredTop));
    popup.style.insetInlineStart = 'auto';
    popup.style.insetInlineEnd = 'auto';
    popup.style.insetBlockStart = 'auto';
    popup.style.insetBlockEnd = 'auto';
    popup.style.left = `${left - cellBounds.left}px`;
    popup.style.top = `${top - cellBounds.top}px`;
}

/** Find a Music Manager disclosure that presents a floating overlay.
 * @param {Element} target Event target inside the manager.
 * @returns {HTMLDetailsElement|null} Floating row/settings/merge/split/title disclosure, or null for inline disclosures.
 */
function adminMusicOverlayDisclosure(target) {
    const disclosure = target.closest(
        '.admin-music-tags, .admin-music-variants, '
        + '.admin-music-locations, .admin-music-song-edit, '
        + '.admin-music-preferences, .admin-music-merge, '
        + '.admin-music-split, .admin-music-album-options',
    );
    return disclosure instanceof HTMLDetailsElement
        ? disclosure : null;
}

/** Close other floating Music Manager overlays while retaining one owner.
 * @param {HTMLDetailsElement|null} retained Overlay allowed to stay open.
 * @returns {void} Closes all matching open overlays except the retained disclosure.
 */
function closeOtherAdminMusicOverlays(retained) {
    const openDisclosures = Array.from(document.querySelectorAll(
        '[data-admin-music] .admin-music-tags[open], '
        + '[data-admin-music] .admin-music-variants[open], '
        + '[data-admin-music] .admin-music-locations[open], '
        + '[data-admin-music] .admin-music-song-edit[open], '
        + '[data-admin-music] .admin-music-preferences[open], '
        + '[data-admin-music] .admin-music-merge[open], '
        + '[data-admin-music] .admin-music-split[open], '
        + '[data-admin-music] .admin-music-album-options[open]',
    ));
    for (const disclosure of openDisclosures) {
        if (disclosure !== retained
            && disclosure instanceof HTMLDetailsElement) {
            disclosure.open = false;
        }
    }
}

/**
 * Capture the current search editor state for an immediate or deferred request.
 * @param {HTMLInputElement} input Search field holding the latest query.
 * @returns {AdminMusicSearchState} Query text, selection, and focus ownership.
 */
function captureAdminMusicSearchState(input) {
    return {
        query: input.value,
        selectionStart: input.selectionStart,
        selectionEnd: input.selectionEnd,
        selectionDirection: input.selectionDirection,
        restoreFocus: document.activeElement === input,
    };
}

/**
 * Start the latest queued query after a pending catalog write finishes.
 * @returns {void} Resumes a current queued search without a second debounce.
 */
function resumePendingAdminMusicSearch() {
    const pending = pendingAdminMusicSearch;
    pendingAdminMusicSearch = null;
    if (!pending || pending.generation !== adminMusicSearchGeneration) return;
    const root = document.querySelector('[data-admin-music]');
    if (!root || root.dataset.busy === '1' || root.dataset.stale === '1') return;
    const form = root.querySelector('form.admin-music-search');
    const input = form?.querySelector('input[name="q"]');
    if (!(form instanceof HTMLFormElement) || !(input instanceof HTMLInputElement)) return;
    input.value = pending.state.query;
    void requestAdminMusicSearch(form, pending.state, pending.generation);
}

/**
 * Bind Music Manager forms once, including forms in refreshed fragments.
 * @returns {void} Installs delegated submit handling.
 */
export function setupAdminMusic() {
    if (document.body.dataset.adminMusicBound === '1') return;
    document.body.dataset.adminMusicBound = '1';
    setupAdminTabsInRoot(document);
    setupAdminMusicAlbumOrdering();
    syncAdminMusicMergeSelection(document);
    document.addEventListener('change', /** Reconcile survivor choices after checkbox selection, including shared range-selection change events. @param {Event} event Changed manager control. @returns {void} Synchronizes merge eligibility and survivor options. */ event => {
        const target = event.target;
        if (target instanceof HTMLInputElement
            && target.matches('input[name="song_ids[]"]')) {
            const root = target.closest('[data-admin-music]');
            if (root) syncAdminMusicMergeSelection(root);
        }
    }, true);
    document.addEventListener('click', /** Toggle a row title editor without changing its independent merge selection. @param {MouseEvent} event Click on a production title action or another document control. @returns {void} Opens, switches, or closes the active row editor. */ event => {
        const target = event.target;
        if (!(target instanceof Element)) {
            closeAdminMusicTitleEditor();
            closeOtherAdminMusicOverlays(null);
            return;
        }
        const contextAction = target.closest('[data-admin-music-context]');
        if (contextAction instanceof HTMLButtonElement) {
            const row = contextAction.closest('tr');
            const disclosureSelector = {
                tags: '.admin-music-tags',
                files: '.admin-music-variants',
                locations: '.admin-music-locations',
            }[contextAction.dataset.adminMusicContext || ''];
            const disclosure = disclosureSelector
                ? row?.querySelector(disclosureSelector) : null;
            if (disclosure instanceof HTMLDetailsElement) {
                event.preventDefault();
                event.stopImmediatePropagation();
                invalidateAdminMusicSearchForEditor();
                closeAdminMusicTitleEditor();
                const shouldOpen = !disclosure.open;
                closeOtherAdminMusicOverlays(shouldOpen ? disclosure : null);
                disclosure.open = shouldOpen;
                if (!shouldOpen) {
                    contextAction.focus();
                    return;
                }
                disclosure.querySelector(':scope > summary')?.focus();
                positionAdminMusicRowDisclosure(disclosure);
            }
            return;
        }
        const clickedDisclosure = adminMusicOverlayDisclosure(target);
        if (!clickedDisclosure) {
            closeOtherAdminMusicOverlays(null);
        } else if (!clickedDisclosure.open) {
            closeOtherAdminMusicOverlays(clickedDisclosure);
        }
        const summary = target.closest('summary');
        const rowDisclosure = summary?.parentElement;
        if (rowDisclosure instanceof HTMLDetailsElement
            && rowDisclosure.closest('.admin-music-table-wrap td')
            && !rowDisclosure.matches('.admin-music-song-edit')) {
            event.preventDefault();
            rowDisclosure.open = !rowDisclosure.open;
            if (rowDisclosure.open) {
                positionAdminMusicRowDisclosure(rowDisclosure);
            }
        }
        const titleAction = target.closest('[data-admin-music-title-edit]');
        if (!(titleAction instanceof HTMLElement)
            || titleAction.tagName !== 'SUMMARY') {
            if (target.closest('details.admin-music-edit')) {
                invalidateAdminMusicSearchForEditor();
            }
            if (activeAdminMusicTitleEditor
                && !activeAdminMusicTitleEditor.popup.contains(target)
                && !activeAdminMusicTitleEditor.trigger.contains(target)) {
                closeAdminMusicTitleEditor();
            }
            return;
        }
        const root = titleAction.closest('[data-admin-music]');
        if (!root || root.dataset.busy === '1' || root.dataset.stale === '1') {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }
        invalidateAdminMusicSearchForEditor();
        if (activeAdminMusicTitleEditor?.trigger === titleAction) {
            event.preventDefault();
            closeAdminMusicTitleEditor();
            return;
        }
        const titleDisclosure = titleAction.closest(
            'details.admin-music-song-edit',
        );
        const titleInput = titleDisclosure?.querySelector(
            'textarea[name="title"]',
        );
        const popup = titleDisclosure?.querySelector(
            ':scope > .admin-music-song-popover',
        );
        if (!(titleDisclosure instanceof HTMLDetailsElement)
            || !(titleInput instanceof HTMLTextAreaElement)
            || !(popup instanceof HTMLElement)) return;
        event.preventDefault();
        closeAdminMusicTitleEditor();
        titleDisclosure.open = true;
        const titleForm = titleInput.form;
        if (!(titleForm instanceof HTMLFormElement)) return;
        const triggerBounds = titleAction.getBoundingClientRect();
        activeAdminMusicTitleEditor = {
            disclosure: titleDisclosure,
            form: titleForm,
            popup,
            titleField: titleInput,
            trigger: titleAction,
            anchor: event.detail > 0
                ? {kind: 'pointer', x: event.clientX, y: event.clientY}
                : {kind: 'keyboard', x: triggerBounds.left, y: triggerBounds.bottom},
        };
        popup.classList.add('admin-music-title-pointer-editor');
        sizeAdminMusicTitleEditor(titleInput);
        titleInput.focus();
    }, true);
    document.addEventListener('input', /** Resize the open title editor as its soft-wrapped text grows. @param {InputEvent} event Input event from a Music Manager editor. @returns {void} Updates only the active row's width. */ event => {
        const titleField = event.target;
        if (titleField instanceof HTMLTextAreaElement
            && titleField.name === 'title') {
            sizeAdminMusicTitleEditor(titleField);
        }
    });
    document.addEventListener('focusin', /** Stop a read before title or tag input takes editing focus. @param {FocusEvent} event Focus entering a Music Manager metadata field. @returns {void} Protects the in-progress value from fragment replacement. */ event => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement
            || target instanceof HTMLTextAreaElement)
            || target.name !== 'title' && target.name !== 'tags'
            || !target.closest('[data-admin-music]')) return;
        invalidateAdminMusicSearchForEditor();
    });
    document.addEventListener('keydown', /** Handle title submission and dismiss floating overlays. @param {KeyboardEvent} event Keyboard input within the document. @returns {void} Submits plain Enter in title fields and returns focus after Escape. */ event => {
        if (event.key === 'Escape') {
            if (activeAdminMusicTitleEditor) {
                const trigger = activeAdminMusicTitleEditor.trigger;
                closeAdminMusicTitleEditor();
                trigger.focus();
                return;
            }
            const target = event.target;
            const focusedDisclosure = target instanceof Element
                ? adminMusicOverlayDisclosure(target) : null;
            const openDisclosure = focusedDisclosure?.open
                ? focusedDisclosure : document.querySelector(
                    '[data-admin-music] .admin-music-tags[open], '
                    + '[data-admin-music] .admin-music-variants[open], '
                    + '[data-admin-music] .admin-music-locations[open], '
                    + '[data-admin-music] .admin-music-song-edit[open], '
                    + '[data-admin-music] .admin-music-preferences[open], '
                    + '[data-admin-music] .admin-music-merge[open], '
                    + '[data-admin-music] .admin-music-split[open], '
                    + '[data-admin-music] .admin-music-album-options[open]',
                );
            if (openDisclosure instanceof HTMLDetailsElement) {
                event.preventDefault();
                openDisclosure.open = false;
                openDisclosure.querySelector(':scope > summary')?.focus();
                return;
            }
        }
        const target = event.target;
        if (event.key !== 'Enter'
            || !(target instanceof HTMLTextAreaElement)
            || target.name !== 'title'
            || !target.form?.matches('[data-admin-music-form]')
            || target.form.querySelector('input[name="action"]')?.value !== 'title'
            || !target.closest('[data-admin-music]')) return;
        event.preventDefault();
        if (event.shiftKey || event.ctrlKey || event.altKey || event.metaKey) return;
        const form = target.form;
        const submitter = form?.querySelector('button[type="submit"]');
        if (!(form instanceof HTMLFormElement)) return;
        if (submitter instanceof HTMLButtonElement) {
            form.requestSubmit(submitter);
        } else {
            form.requestSubmit();
        }
    }, true);
    window.addEventListener('resize', /** Reflow and clamp the active title editor after viewport resizing. @returns {void} Recalculates its width, wrapped height and placement. */ () => {
        const active = activeAdminMusicTitleEditor;
        const titleField = active?.titleField;
        if (titleField instanceof HTMLTextAreaElement) {
            sizeAdminMusicTitleEditor(titleField);
        }
    });
    window.addEventListener('scroll', /** Keep an active title popup at its saved viewport anchor while its table cell moves. @returns {void} Recomputes cell-relative popup offsets. */ () => {
        const active = activeAdminMusicTitleEditor;
        if (active) {
            positionAdminMusicTitleEditor(
                active.popup,
                active.trigger,
                active.anchor,
            );
        }
    }, true);
    document.addEventListener('input', /** Invalidate old reads immediately and debounce only the newest query. @param {InputEvent} event Search-field input event. @returns {void} Queues the current fragment request. */ event => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || input.name !== 'q'
            || !input.form?.matches('form.admin-music-search')) return;
        const root = input.closest('[data-admin-music]');
        if (!root || root.dataset.stale === '1') return;
        const generation = invalidateAdminMusicSearch();
        const state = captureAdminMusicSearchState(input);
        if (root.dataset.busy === '1') {
            pendingAdminMusicSearch = {generation, state};
            return;
        }
        pendingAdminMusicSearch = null;
        adminMusicSearchTimer = setTimeout(
            /** Launch the newest query after its bounded typing pause. @returns {void} Starts one fragment read. */
            () => {
                adminMusicSearchTimer = null;
                void requestAdminMusicSearch(input.form, state, generation);
            },
            ADMIN_MUSIC_SEARCH_DEBOUNCE_MS,
        );
    });
    document.addEventListener('submit', /** Handle a manager form before generic Admin handlers. @param {SubmitEvent} event Submitted event from a manager form. @returns {Promise<void>} Sends the mutation and refreshes its owned page fragment. */ async event => {
        const form = event.target;
        if (form instanceof HTMLFormElement
            && form.matches('form.admin-music-search')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            const input = form.querySelector('input[name="q"]');
            const root = form.closest('[data-admin-music]');
            if (!(input instanceof HTMLInputElement) || !root
                || root.dataset.stale === '1') return;
            const generation = invalidateAdminMusicSearch();
            const state = captureAdminMusicSearchState(input);
            if (root.dataset.busy === '1') {
                pendingAdminMusicSearch = {generation, state};
                return;
            }
            pendingAdminMusicSearch = null;
            await requestAdminMusicSearch(form, state, generation);
            return;
        }
        if (!(form instanceof HTMLFormElement)
            || !form.matches('[data-admin-music-form]')) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const root = form.closest('[data-admin-music]');
        if (!root || root.dataset.busy === '1' || root.dataset.stale === '1') return;
        pendingAdminMusicSearch = null;
        invalidateAdminMusicSearch();
        let question = form.querySelector('[data-confirm]')?.dataset.confirm
            || form.querySelector('[data-confirm]')?.getAttribute('data-confirm');
        if (form.querySelector('input[name="action"]')?.value === 'merge') {
            syncAdminMusicMergeSelection(root);
            const mergeSelect = form.querySelector('select[name="song_id"]');
            const mergeButton = form.querySelector('button[type="submit"]');
            if (!(mergeSelect instanceof HTMLSelectElement)
                || !(mergeButton instanceof HTMLButtonElement)
                || mergeButton.disabled
                || !Array.from(root.querySelectorAll(
                    'input[name="song_ids[]"]:checked',
                )).some(input => input.value === mergeSelect.value)) return;
            const selected = Array.from(root.querySelectorAll(
                'input[name="song_ids[]"]:checked'
            )).map(/** Describe the selected song represented by one checkbox. @param {HTMLInputElement} input Selected catalog checkbox. @returns {string} Preview text for the confirmation. */ input => {
                const row = input.closest('tr');
                const name = row?.querySelector('.admin-music-select span')?.textContent?.trim() || '';
                const files = Array.from(row?.querySelectorAll('.admin-music-variants li') || [])
                    .map(/** Return one physical variant label. @param {HTMLLIElement} item Variant list item. @returns {string} Visible filename and source details. */ item => item.textContent.trim());
                const tags = row?.querySelector('input[name="tags"]')?.value?.trim()
                    || row?.querySelector('.admin-music-tag-list')?.textContent?.trim()
                    || '';
                const usageItems = Array.from(row?.children[2]?.querySelectorAll('li') || [])
                    .map(/** Return one logical catalog usage location. @param {HTMLLIElement} item Usage list item. @returns {string} Gallery or album path associated with the song. */ item => item.textContent.trim());
                return `${name}${tags ? `\n  ${tags}` : ''}`
                    + `${files.length ? `\n  ${files.join('\n  ')}` : ''}`
                    + `${usageItems.length ? `\n  ${root.dataset.usageLabel || ''}: ${usageItems.join('\n  ')}` : ''}`;
            });
            const survivor = form.querySelector('select[name="song_id"]')?.selectedOptions[0]?.textContent?.trim() || '';
            const tagPolicy = form.querySelector('select[name="tag_policy"]')?.value || 'union';
            question = `${root.dataset.mergeConfirm || ''}\n\n${selected.join('\n')}`
                + `\n\n${root.dataset.survivorLabel || ''}: ${survivor}`
                + `\n${tagPolicy === 'survivor'
                    ? root.dataset.mergeSurvivor : root.dataset.mergeUnion || ''}`;
        }
        if (question && !window.confirm(question)) return;
        const submitter = event.submitter;
        const body = new FormData(form);
        if (submitter?.name) body.set(submitter.name, submitter.value);
        body.set('ajax', '1');
        const buttons = Array.from(root.querySelectorAll('button'));
        const previous = buttons.map(/** Capture one button's prior state for request cleanup. @param {HTMLButtonElement} button Manager action button. @returns {boolean} Whether the button was already disabled. */ button => button.disabled);
        const requestController = new AbortController();
        const requestTimer = setTimeout(
            /** Stop a write or owned refresh that exceeded its request budget. @returns {void} Aborts the active fetch. */
            () => requestController.abort(),
            ADMIN_MUSIC_REQUEST_TIMEOUT_MS,
        );
        root.dataset.busy = '1';
        buttons.forEach(/** Prevent concurrent submissions while this mutation is pending. @param {HTMLButtonElement} button Manager action button. @returns {void} Disables the pending action. */ button => { button.disabled = true; });
        try {
            const endpoint = form.getAttribute('action');
            const response = await fetch(endpoint, {
                method: 'POST', body, credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                signal: requestController.signal,
            });
            const result = await response.json();
            if (!response.ok || result.ok !== true) {
                throw new Error(String(result.message || root.dataset.error || ''));
            }
            const completion = await completeAdminMutation(result, {
                refreshPanel: async (panel, envelope, guard) => {
                    const fragmentResponse = await fetch(panel.refresh_url, {
                        credentials: 'same-origin',
                        headers: {'Accept': 'text/html'},
                        signal: requestController.signal,
                    });
                    if (!guard.isCurrent() || !fragmentResponse.ok) return false;
                    const template = document.createElement('template');
                    template.innerHTML = await fragmentResponse.text();
                    const replacement = template.content.querySelector('[data-admin-music]');
                    if (!replacement || !root.isConnected || !guard.isCurrent()) return false;
                    replacement.querySelectorAll('.admin-music-notice')
                        .forEach(/** Remove stale server-side flash text before showing this envelope. @param {Element} noticeNode Previous notice in the refreshed fragment. @returns {void} Avoids duplicate completion messages. */ noticeNode => noticeNode.remove());
                    const notice = document.createElement('p');
                    notice.className = 'admin-music-notice';
                    notice.setAttribute('role', 'status');
                    notice.setAttribute('aria-live', 'polite');
                    notice.textContent = String(envelope.message || '');
                    replacement.prepend(notice);
                    if (activeAdminMusicTitleEditor
                        && root.contains(activeAdminMusicTitleEditor.popup)) {
                        closeAdminMusicTitleEditor();
                    }
                    preserveAdminMusicTabBindings(root, replacement);
                    root.replaceWith(replacement);
                    setupAdminTabsInRoot(replacement);
                    setupAdminMusicAlbumOrdering();
                    syncAdminMusicMergeSelection(replacement);
                    replacement.querySelector('[role="status"]')?.focus();
                    return true;
                },
            });
            if (completion.synchronized !== true) {
                const activeRoot = document.querySelector('[data-admin-music]');
                if (activeRoot instanceof HTMLElement) {
                    activeRoot.dataset.stale = '1';
                    let status = activeRoot.querySelector('[data-admin-music-status]');
                    if (!status) {
                        status = document.createElement('p');
                        status.dataset.adminMusicStatus = '1';
                        activeRoot.prepend(status);
                    }
                    status.setAttribute('role', 'alert');
                    status.textContent = String(activeRoot.dataset.refreshError || '');
                    activeRoot.querySelectorAll('button, input, select, textarea')
                        .forEach(/** Lock every write and navigation control until the user reloads. @param {HTMLButtonElement|HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} control Manager form control. @returns {void} Prevents replay after an acknowledged save. */ control => { control.disabled = true; });
                }
            }
        } catch (error) {
            const timedOut = requestController.signal.aborted;
            if (timedOut && root.isConnected) {
                root.dataset.stale = '1';
                root.querySelectorAll('button, input, select, textarea')
                    .forEach(/** Lock all form controls when a timed-out write has an unknown outcome. @param {HTMLButtonElement|HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} control Manager form control. @returns {void} Prevents replay until reload resolves the server state. */ control => { control.disabled = true; });
            }
            let status = root.querySelector('[data-admin-music-status]');
            if (!status) {
                status = document.createElement('p');
                status.dataset.adminMusicStatus = '1';
                status.setAttribute('role', 'alert');
                root.prepend(status);
            }
            status.textContent = timedOut
                ? String(root.dataset.timeoutError || root.dataset.refreshError || '')
                : error instanceof Error
                    ? error.message : String(root.dataset.error || '');
        } finally {
            clearTimeout(requestTimer);
            root.dataset.busy = '0';
            if (root.dataset.stale !== '1') {
                // Restore captured controls before reconciling current selection.
                buttons.forEach((button, index) => {
                    button.disabled = previous[index];
                });
                if (root.isConnected) syncAdminMusicMergeSelection(root);
            }
            resumePendingAdminMusicSearch();
        }
    }, true);
}
