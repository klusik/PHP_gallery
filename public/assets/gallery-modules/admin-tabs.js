/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-tabs.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides admin tab behavior shared by full pages and side-panel fragments.
 *
 * Responsibilities:
 *   - Attach behavior to existing server-rendered markup
 *   - Keep DOM interaction predictable and readable
 *   - Avoid unnecessary layout work in performance-sensitive paths
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
 *   2026-05-12
 */

const legacyAdminTabHashes = new Map([
    ['#admin-galleries', '#admin-tab-galleries'],
    ['#admin-ordering', '#admin-tab-galleries'],
    ['#admin-thumbnails', '#admin-tab-maintenance'],
    ['#admin-cache', '#admin-tab-maintenance'],
    ['#admin-migrations', '#admin-tab-maintenance'],
    ['#admin-appearance', '#admin-tab-overview'],
]);

// Function `normalizedAdminTabHash` executes this focused behavior.
function normalizedAdminTabHash(hash) {
    if (!hash) {
        return '';
    }
    return legacyAdminTabHashes.get(hash) || hash;
}

export function setupAdminTabs(root = document) {
    setupAdminTabsInRoot(root);
}

/**
 * Attach admin tab behavior inside one document area.
 *
 * @param {ParentNode} root DOM root that contains admin tab controls.
 * @returns {void}
 */
export function setupAdminTabsInRoot(root) {
    root.querySelectorAll('[data-admin-tabs]').forEach((tabsRoot) => {
        if (!(tabsRoot instanceof HTMLElement) || tabsRoot.dataset.adminTabsBound === '1') {
            return;
        }
        tabsRoot.dataset.adminTabsBound = '1';
        // tabs stores state or configuration for the admin tab flow.
        const tabs = Array.from(tabsRoot.querySelectorAll('[role="tab"][data-admin-tab-target]'));
        if (!tabs.length) {
            return;
        }
        // panels stores state or configuration for the admin tab flow.
        const panels = tabs
            .map((tab) => document.getElementById(tab.dataset.adminTabTarget || ''))
            .filter((panel) => panel instanceof HTMLElement && panel.matches('[data-admin-tab-panel]'));
        if (!panels.length) {
            return;
        }
        // shouldManageHash stores whether this tab group owns the browser URL hash.
        const shouldManageHash = !tabsRoot.closest('[data-admin-side-panel]');

        // activateTab stores behavior for selecting one tab and hiding the other panels.
        const activateTab = (targetId, options = {}) => {
            // targetPanel stores the panel selected by the current hash or click.
            const targetPanel = panels.find((panel) => panel.id === targetId) || panels[0];
            if (!targetPanel) {
                return;
            }
            tabs.forEach((tab) => {
                // isSelected stores whether this control owns the selected panel.
                const isSelected = tab.dataset.adminTabTarget === targetPanel.id;
                tab.classList.toggle('is-active', isSelected);
                tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                tab.setAttribute('tabindex', isSelected ? '0' : '-1');
            });
            panels.forEach((panel) => {
                // isSelected stores whether this panel should be visible.
                const isSelected = panel.id === targetPanel.id;
                panel.hidden = !isSelected;
                panel.classList.toggle('is-active', isSelected);
            });
            if (options.focusTab) {
                tabs.find((tab) => tab.dataset.adminTabTarget === targetPanel.id)?.focus();
            }
            if (options.updateHash && shouldManageHash) {
                const nextHash = `#${targetPanel.id}`;
                if (window.location.hash !== nextHash) {
                    window.history.pushState(null, '', nextHash);
                }
            }
            tabsRoot.closest('form, [data-admin-side-panel-body], main')?.querySelectorAll('input[type="hidden"][name="return_tab"]').forEach((input) => {
                if (input instanceof HTMLInputElement) {
                    input.value = targetPanel.id;
                }
            });
        };

        // activeHash stores the hash that should select the initial tab.
        const activeHash = shouldManageHash ? normalizedAdminTabHash(window.location.hash) : '';
        if (activeHash && activeHash !== window.location.hash) {
            window.history.replaceState(null, '', activeHash);
        }
        activateTab((activeHash || '').replace(/^#/, '') || tabs.find((tab) => tab.getAttribute('aria-selected') === 'true')?.dataset.adminTabTarget || tabs[0].dataset.adminTabTarget || '');

        tabs.forEach((tab) => {
            tab.addEventListener('click', (event) => {
                // targetId stores the panel id requested by the clicked tab.
                const targetId = tab.dataset.adminTabTarget || '';
                if (!targetId || !panels.some((panel) => panel.id === targetId)) {
                    return;
                }
                event.preventDefault();
                activateTab(targetId, {updateHash: true});
            });
            tab.addEventListener('keydown', (event) => {
                // currentIndex stores the focused tab position.
                const currentIndex = tabs.indexOf(tab);
                if (currentIndex < 0) {
                    return;
                }
                // nextIndex stores the tab position selected by the keyboard.
                let nextIndex = currentIndex;
                if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                    nextIndex = (currentIndex + 1) % tabs.length;
                } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                    nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
                } else if (event.key === 'Home') {
                    nextIndex = 0;
                } else if (event.key === 'End') {
                    nextIndex = tabs.length - 1;
                } else if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    activateTab(tab.dataset.adminTabTarget || '', {updateHash: true});
                    return;
                } else {
                    return;
                }
                event.preventDefault();
                activateTab(tabs[nextIndex].dataset.adminTabTarget || '', {updateHash: true, focusTab: true});
            });
        });

        if (!shouldManageHash) {
            return;
        }

        // handleHashNavigation stores behavior shared by hashchange and history traversal.
        const handleHashNavigation = () => {
            // hash stores the normalized browser hash after navigation.
            const hash = normalizedAdminTabHash(window.location.hash);
            if (!hash) {
                return;
            }
            if (hash !== window.location.hash) {
                window.history.replaceState(null, '', hash);
            }
            activateTab(hash.replace(/^#/, ''));
        };

        window.addEventListener('hashchange', handleHashNavigation);
        window.addEventListener('popstate', handleHashNavigation);
    });
}

/**
 * Return the active tab id inside one injected admin region.
 *
 * @param {ParentNode|null} root DOM root to inspect.
 * @returns {string} Active tab target id or an empty string.
 */
export function activeAdminTabId(root) {
    if (!root || typeof root.querySelector !== 'function') {
        return '';
    }
    const selected = root.querySelector('[role="tab"][data-admin-tab-target][aria-selected="true"]');
    return selected instanceof HTMLElement ? String(selected.dataset.adminTabTarget || '') : '';
}

/**
 * Activate one tab after replacing server-rendered panel HTML.
 *
 * @param {ParentNode} root DOM root that contains admin tabs.
 * @param {string} targetId Tab panel id to show.
 * @returns {void}
 */
export function activateAdminTabInRoot(root, targetId) {
    if (!targetId) {
        return;
    }
    const tab = root.querySelector(`[role="tab"][data-admin-tab-target="${CSS.escape(targetId)}"]`);
    if (tab instanceof HTMLElement) {
        tab.click();
    }
}
