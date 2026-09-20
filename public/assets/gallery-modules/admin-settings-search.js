/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Normalize translated search terms and reveal matching settings destinations.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-settings-search.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides a local Spotlight-style search over the centralized Settings registry.
  *
 * Author:
 *   Rudolf Klusal
*/

import {
    ADMIN_SETTINGS_SEARCH_RESULT_LIMIT,
    ADMIN_SETTINGS_SEARCH_PREFIX_SCORE,
    ADMIN_SETTINGS_SEARCH_WORD_SCORE,
    ADMIN_SETTINGS_SEARCH_SUBSTRING_SCORE,
    ADMIN_SETTINGS_SEARCH_KEYWORD_SCORE,
    ADMIN_SETTINGS_SEARCH_HIGHLIGHT_MS,
} from './admin-interaction-policy.js?v=20260920-admin-interaction-policy-v1';

/** Normalize searchable text while keeping matching friendly across translated accents. */
function normalizeSettingsSearchText(value) {
    return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase().trim();
}

/**
 * Attach one Settings search instance and its local ranking/activation handlers.
 * @param {HTMLElement} root Server-rendered Settings search owner.
 * @return {void} Marks a valid unbound control and installs its listeners once.
 */
function setupAdminSettingsSearchRoot(root) {
    if (!(root instanceof HTMLElement) || root.dataset.adminSettingsSearchBound === '1') {
        return;
    }
    root.dataset.adminSettingsSearchBound = '1';
    const input = root.querySelector('[data-admin-settings-search-input]');
    const results = root.querySelector('[data-admin-settings-search-results]');
    const status = root.querySelector('[data-admin-settings-search-status]');
    const clear = root.querySelector('[data-admin-settings-search-clear]');
    const items = Array.from(root.querySelectorAll('[data-admin-settings-search-result]'));
    if (!(input instanceof HTMLInputElement) || !(results instanceof HTMLElement) || !(status instanceof HTMLElement) || !(clear instanceof HTMLButtonElement)) {
        return;
    }

    let visibleItems = [];
    let activeIndex = -1;
    /** Close the result popover and clear its active descendant. */
    const closeResults = () => {
        results.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        activeIndex = -1;
    };
    /** Move keyboard focus styling to the requested visible result. */
    const setActive = (index) => {
        if (!visibleItems.length) {
            activeIndex = -1;
            input.removeAttribute('aria-activedescendant');
            return;
        }
        activeIndex = (index + visibleItems.length) % visibleItems.length;
        visibleItems.forEach((item, itemIndex) => {
            const active = itemIndex === activeIndex;
            item.classList.toggle('is-active', active);
            item.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        input.setAttribute('aria-activedescendant', visibleItems[activeIndex].id);
        visibleItems[activeIndex].scrollIntoView({block: 'nearest'});
    };
    /**
     * Score one registry item against normalized search tokens.
     * @param {HTMLElement} item Server-rendered Settings registry result.
     * @param {string[]} tokens Nonempty normalized query tokens.
     * @return {number} Additive relevance score, or -1 when any token is absent.
     */
    const scoreItem = (item, tokens) => {
        const text = normalizeSettingsSearchText(item.dataset.searchText);
        const label = normalizeSettingsSearchText(item.dataset.searchLabel);
        if (!tokens.every((token) => text.includes(token))) {
            return -1;
        }
        return tokens.reduce(
            /** Accumulate label relevance without changing token order or match classes. @param {number} score Running relevance. @param {string} token Normalized query word. @return {number} Updated additive score. */
            (score, token) => score + (label.startsWith(token) ? ADMIN_SETTINGS_SEARCH_PREFIX_SCORE
            : label.includes(` ${token}`) ? ADMIN_SETTINGS_SEARCH_WORD_SCORE
            : label.includes(token) ? ADMIN_SETTINGS_SEARCH_SUBSTRING_SCORE : ADMIN_SETTINGS_SEARCH_KEYWORD_SCORE), 0);
    };
    /**
     * Recompute and render results for the current query.
     * @return {void} Replaces the bounded visible result set and its accessible active row.
     */
    const update = () => {
        const query = normalizeSettingsSearchText(input.value);
        const tokens = query.split(/\s+/).filter(Boolean);
        clear.hidden = query === '';
        items.forEach((item) => { item.hidden = true; item.classList.remove('is-active'); item.setAttribute('aria-selected', 'false'); });
        if (!tokens.length) {
            status.textContent = '';
            visibleItems = [];
            closeResults();
            return;
        }
        visibleItems = items.map((item) => ({item, score: scoreItem(item, tokens)}))
            .filter((match) => match.score >= 0)
            .sort((left, right) => right.score - left.score || String(left.item.dataset.searchLabel).localeCompare(String(right.item.dataset.searchLabel)))
            .slice(0, ADMIN_SETTINGS_SEARCH_RESULT_LIMIT)
            .map((match) => match.item);
        visibleItems.forEach((item) => { item.hidden = false; });
        status.textContent = visibleItems.length
            ? `${visibleItems.length} ${root.dataset.resultsLabel || 'matching settings'}`
            : (root.dataset.emptyLabel || 'No matching settings found.');
        results.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        setActive(visibleItems.length ? 0 : -1);
    };
    /**
     * Open and highlight the settings control represented by a result link.
     * @param {HTMLAnchorElement} item Selected Settings registry result.
     * @return {void} Activates the local tab, or follows the existing link when its target is absent.
     */
    const activate = (item) => {
        if (!(item instanceof HTMLAnchorElement)) {
            return;
        }
        const sectionPanelId = `settings-${item.dataset.searchSection || 'general'}`;
        const tab = document.querySelector(`[data-admin-tab-target="${CSS.escape(sectionPanelId)}"]`);
        if (tab instanceof HTMLElement) {
            tab.click();
        }
        closeResults();
        window.requestAnimationFrame(
            /** Focus and briefly highlight the destination after its Settings tab opens. @return {void} Falls back to the existing link only when no local target exists. */
            () => {
            const target = document.getElementById(item.dataset.searchTarget || '');
            if (!(target instanceof HTMLElement)) {
                window.location.href = item.href;
                return;
            }
            target.scrollIntoView({behavior: 'smooth', block: 'center'});
            target.focus({preventScroll: true});
            target.classList.add('is-search-highlighted');
            window.setTimeout(() => target.classList.remove('is-search-highlighted'), ADMIN_SETTINGS_SEARCH_HIGHLIGHT_MS);
        });
    };

    input.addEventListener('input', update);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            setActive(activeIndex + (event.key === 'ArrowDown' ? 1 : -1));
        } else if (event.key === 'Enter' && activeIndex >= 0) {
            event.preventDefault();
            activate(visibleItems[activeIndex]);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            closeResults();
        }
    });
    clear.addEventListener('click', () => { input.value = ''; update(); input.focus(); });
    items.forEach((item) => item.addEventListener('click', (event) => { event.preventDefault(); activate(item); }));
    document.addEventListener('pointerdown', (event) => { if (!root.contains(event.target)) closeResults(); });
}

/** Initialize all centralized Settings search controls in a document fragment. */
export function setupAdminSettingsSearch(root = document) {
    root.querySelectorAll('[data-admin-settings-search]').forEach(setupAdminSettingsSearchRoot);
}
