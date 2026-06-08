/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-nested-tabs.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides reusable local subtab behavior for long Admin settings panels.
 *
 * Responsibilities:
 *   - Activate nested Admin subtab panels without changing the browser hash
 *   - Keep keyboard navigation and ARIA state synchronized
 *   - Avoid coupling Theme-specific layout decisions to generic tab behavior
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

/**
 * Return the DOM scope that owns one subtab group.
 *
 * A scope keeps multiple subtab groups on the same admin page independent from
 * each other while still allowing panels to be rendered after the tab row.
 *
 * @param {HTMLElement} tabsRoot Subtab navigation element.
 * @returns {ParentNode} Scoped DOM root used to find matching panels.
 */
function adminSubtabScope(tabsRoot) {
    return tabsRoot.closest('[data-admin-subtab-scope]') || tabsRoot.parentElement || document;
}

function setAdminSubtabPanelVisibility(panel, isVisible) {
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
 * Attach behavior to one reusable local subtab group.
 *
 * @param {HTMLElement} tabsRoot Subtab navigation root.
 * @returns {void}
 */
function setupAdminNestedTabGroup(tabsRoot) {
    if (tabsRoot.dataset.adminSubtabsBound === '1') {
        return;
    }
    tabsRoot.dataset.adminSubtabsBound = '1';

    // tabs stores the clickable controls rendered by PHP view helpers.
    const tabs = Array.from(tabsRoot.querySelectorAll('[role="tab"][data-admin-subtab-target]'));
    if (!tabs.length) {
        return;
    }

    // scope stores the nearest logical settings group containing this subtab row and its panels.
    const scope = adminSubtabScope(tabsRoot);
    // panels stores only the panels targeted by this control row.
    const panels = tabs
        .map((tab) => scope.querySelector(`#${CSS.escape(tab.dataset.adminSubtabTarget || '')}`))
        .filter((panel) => panel instanceof HTMLElement && panel.matches('[data-admin-subtab-panel]'));
    if (!panels.length) {
        return;
    }

    /**
     * Select one local subtab panel and hide the others.
     *
     * @param {string} targetId Panel id requested by a click or keyboard event.
     * @param {{focusTab?: boolean}} options Activation options.
     * @returns {void}
     */
    const activateSubtab = (targetId, options = {}) => {
        // targetPanel stores the matching panel or the first available panel as a safe fallback.
        const targetPanel = panels.find((panel) => panel.id === targetId) || panels[0];
        if (!targetPanel) {
            return;
        }

        tabs.forEach((tab) => {
            // isSelected stores whether this control owns the selected panel.
            const isSelected = tab.dataset.adminSubtabTarget === targetPanel.id;
            tab.classList.toggle('is-active', isSelected);
            tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
            tab.setAttribute('tabindex', isSelected ? '0' : '-1');
        });

        panels.forEach((panel) => {
            // isSelected stores whether this panel should be visible.
            const isSelected = panel.id === targetPanel.id;
            setAdminSubtabPanelVisibility(panel, isSelected);
        });

        if (options.focusTab) {
            tabs.find((tab) => tab.dataset.adminSubtabTarget === targetPanel.id)?.focus();
        }
    };

    // initialTargetId stores the server-selected panel, or the first rendered subtab.
    const initialTargetId = tabs.find((tab) => tab.getAttribute('aria-selected') === 'true')?.dataset.adminSubtabTarget || tabs[0].dataset.adminSubtabTarget || '';
    activateSubtab(initialTargetId);

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            activateSubtab(tab.dataset.adminSubtabTarget || '');
        });

        tab.addEventListener('keydown', (event) => {
            // currentIndex stores the focused subtab position.
            const currentIndex = tabs.indexOf(tab);
            if (currentIndex < 0) {
                return;
            }

            // nextIndex stores the subtab position selected by the keyboard.
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
                activateSubtab(tab.dataset.adminSubtabTarget || '');
                return;
            } else {
                return;
            }

            event.preventDefault();
            activateSubtab(tabs[nextIndex].dataset.adminSubtabTarget || '', {focusTab: true});
        });
    });
}

/**
 * Attach all reusable local Admin subtab groups in one DOM root.
 *
 * @param {ParentNode} root DOM root that contains admin subtab controls.
 * @returns {void}
 */
export function setupAdminNestedTabs(root = document) {
    root.querySelectorAll('[data-admin-subtabs]').forEach((tabsRoot) => {
        if (tabsRoot instanceof HTMLElement) {
            setupAdminNestedTabGroup(tabsRoot);
        }
    });
}
