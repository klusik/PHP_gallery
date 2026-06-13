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
 *   2026-06-08
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
/**
 * Handle normalized admin tab hash.
 *
 * Used by browser-side gallery behavior.
 *
 * @param {boolean} hash Hash value.
 * @return {string} Text result for the caller.
 */
function normalizedAdminTabHash(hash) {
    if (!hash) {
        return '';
    }
    return legacyAdminTabHashes.get(hash) || hash;
}


/**
 * Set admin panel visibility.
 *
 * Used by browser-side gallery behavior.
 *
 * @param {HTMLElement} panel Panel value.
 * @param {boolean} isVisible Is visible flag.
 */
function setAdminPanelVisibility(panel, isVisible) {
    panel.classList.remove('is-admin-panel-entering');
    if (!isVisible) {
        panel.hidden = true;
        panel.classList.remove('is-active');
        return;
    }

    const wasHidden = panel.hidden;
    panel.hidden = false;
    panel.classList.add('is-active');
    if (wasHidden && typeof window.requestAnimationFrame === 'function') {
        panel.classList.add('is-admin-panel-entering');
        window.requestAnimationFrame(() => {
            panel.classList.remove('is-admin-panel-entering');
        });
    }
}


/**
 * Keep the persistent admin sidebar aligned with hash-selected dashboard tabs.
 *
 * The server cannot read URL fragments, so links that point to the same admin
 * page need a small client-side correction after the dashboard tab module has
 * resolved the active hash. This keeps Dashboard active for the overview tab
 * and All galleries active for #admin-tab-galleries.
 *
 * @param {string} activeHash Normalized URL fragment including the leading #.
 * @return {void} Result value for the caller.
 */
function syncAdminSidebarHashSelection(activeHash) {
    const sidebar = document.querySelector('.admin-sidebar');
    if (!(sidebar instanceof HTMLElement)) {
        return;
    }

    const currentUrl = new URL(window.location.href);
    const currentPage = currentUrl.searchParams.get('page') || '';
    const links = Array.from(sidebar.querySelectorAll('.admin-menu-link'))
        .filter((link) => link instanceof HTMLAnchorElement)
        .filter((link) => {
            try {
                const linkUrl = new URL(link.href, window.location.href);
                const linkPage = linkUrl.searchParams.get('page') || '';

                // Hash-only admin dashboard links can be rendered through rewritten
                // or non-rewritten URLs. Compare the logical page first so the
                // sidebar still updates when /admin and /index.php?page=admin
                // point to the same dashboard.
                if (currentPage === 'admin' && linkPage === 'admin') {
                    return true;
                }

                return linkUrl.origin === currentUrl.origin
                    && linkUrl.pathname === currentUrl.pathname
                    && linkUrl.search === currentUrl.search;
            } catch (error) {
                return false;
            }
        });

    if (!links.length) {
        return;
    }

    const normalizedHash = normalizedAdminTabHash(activeHash || '');
    const targetLink = normalizedHash
        ? links.find((link) => normalizedAdminTabHash(link.hash || '') === normalizedHash)
        : links.find((link) => !link.hash);

    if (!(targetLink instanceof HTMLAnchorElement)) {
        return;
    }

    links.forEach((link) => {
        link.classList.toggle('is-active', link === targetLink);
    });
}

/**
 * Handle setup admin tabs.
 *
 * Used by browser-side gallery behavior.
 *
 * @param {*} root Root value.
 */
export function setupAdminTabs(root = document) {
    setupAdminTabsInRoot(root);
}

/**
 * Attach admin tab behavior inside one document area.
 *
 * @param {ParentNode} root DOM root that contains admin tab controls.
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
        /**
         * Handle activate tab.
         *
         * Used by browser-side gallery behavior.
         *
         * @param {number} targetId Target id identifier.
         * @param {object} options Optional behavior flags.
         */
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
                setAdminPanelVisibility(panel, isSelected);
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
            if (shouldManageHash) {
                syncAdminSidebarHashSelection(`#${targetPanel.id}`);
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
        const initialTargetId = (activeHash || '').replace(/^#/, '') || tabs.find((tab) => tab.getAttribute('aria-selected') === 'true')?.dataset.adminTabTarget || tabs[0].dataset.adminTabTarget || '';
        activateTab(initialTargetId);
        syncAdminSidebarHashSelection(initialTargetId ? `#${initialTargetId}` : '');

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
        /**
         * Handle hash navigation.
         *
         * Used by browser-side gallery behavior.
         */
        const handleHashNavigation = () => {
            // hash stores the normalized browser hash after navigation.
            const hash = normalizedAdminTabHash(window.location.hash);
            if (!hash) {
                syncAdminSidebarHashSelection('');
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
 * @return {string} Active tab target id or an empty string.
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
