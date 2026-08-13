/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-settings-search.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides a local Spotlight-style search over the centralized Settings registry.
 */

/** Normalize searchable text while keeping matching friendly across translated accents. */
function normalizeSettingsSearchText(value) {
    return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase().trim();
}

/** Attach one Settings search instance. */
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
    /** Score one registry item against normalized search tokens. */
    const scoreItem = (item, tokens) => {
        const text = normalizeSettingsSearchText(item.dataset.searchText);
        const label = normalizeSettingsSearchText(item.dataset.searchLabel);
        if (!tokens.every((token) => text.includes(token))) {
            return -1;
        }
        return tokens.reduce((score, token) => score + (label.startsWith(token) ? 100 : label.includes(` ${token}`) ? 70 : label.includes(token) ? 50 : 10), 0);
    };
    /** Recompute and render results for the current query. */
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
            .slice(0, 12)
            .map((match) => match.item);
        visibleItems.forEach((item) => { item.hidden = false; });
        status.textContent = visibleItems.length
            ? `${visibleItems.length} ${root.dataset.resultsLabel || 'matching settings'}`
            : (root.dataset.emptyLabel || 'No matching settings found.');
        results.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        setActive(visibleItems.length ? 0 : -1);
    };
    /** Open and highlight the settings control represented by a result link. */
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
        window.requestAnimationFrame(() => {
            const target = document.getElementById(item.dataset.searchTarget || '');
            if (!(target instanceof HTMLElement)) {
                window.location.href = item.href;
                return;
            }
            target.scrollIntoView({behavior: 'smooth', block: 'center'});
            target.focus({preventScroll: true});
            target.classList.add('is-search-highlighted');
            window.setTimeout(() => target.classList.remove('is-search-highlighted'), 1800);
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
