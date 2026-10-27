/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-navigation.js
 * Module Type: Browser Module
 * Purpose: Adapt grouped Admin navigation to an icon rail and mobile disclosure.
 * Responsibilities: Keep preferences browser-local, preserve focus and rebind
 * replacement sidebars without changing routes or server-side permissions.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/**
 * Match the existing Admin workspace's single-column breakpoint.
 * Type: string. Units: CSS pixels. Scope: Admin navigation.
 * Consumers: matchMedia and admin-navigation.css.
 * Rationale: 1100px replaces the existing stacked sidebar before it pushes
 * page content below every navigation group.
 */
const MOBILE_QUERY = '(max-width: 1100px)';
/**
 * Keep the open disclosure clear of the bottom viewport edge.
 * Type: number. Units: CSS pixels. Scope: the open mobile navigation panel.
 * Consumers: syncGeometry.
 * Rationale: 8px matches the compact workspace edge gap and retains scrolling
 * room when a short landscape viewport cannot contain every group.
 */
const VIEWPORT_EDGE_GAP_PX = 8;
/**
 * Scope the desktop preference to this installation across rewritten routes.
 * Type: string. Units: one localStorage key. Scope: one origin and asset mount.
 * Consumers: initial preference reads, toggle writes and cross-tab storage events.
 * Rationale: the php-gallery:admin-navigation: prefix plus the asset mount keeps
 * separate gallery installations on the same origin from sharing a preference.
 */
const STORAGE_KEY = 'php-gallery:admin-navigation:'
    + new URL('../', import.meta.url).pathname;
let navigationBound = false;

/**
 * Enhance the existing Admin shell without requiring persistent server storage.
 * @return {void} Installs one lifecycle, or leaves non-Admin documents untouched.
 */
export function setupAdminNavigation() {
    const shell = document.querySelector('.admin-shell');
    if (!(shell instanceof HTMLElement) || navigationBound) return;
    navigationBound = true;
    const mobile = window.matchMedia(MOBILE_QUERY);
    let collapsed = false;
    let open = false;
    let observedSidebar = null;
    const selectionObserver = new MutationObserver(syncCurrentPage);
    try {
        collapsed = window.localStorage.getItem(STORAGE_KEY) === 'collapsed';
    } catch {
        // Admin navigation owns this permanent fallback: privacy settings may
        // deny storage. Keep the default expanded and later changes in memory;
        // navigation must remain usable without an available storage backend.
    }

    /**
     * Keep the accessible current destination aligned with dashboard tab styling.
     * @return {void} Updates page markers after server or hash-based selection.
     */
    function syncCurrentPage() {
        shell.querySelectorAll('.admin-menu-link').forEach((link) => {
            if (link.classList.contains('is-active')) {
                link.setAttribute('aria-current', 'page');
            } else link.removeAttribute('aria-current');
        });
    }

    /**
     * Fit navigation below the actual sticky header and inside the viewport.
     * @return {void} Updates geometry variables on the owning Admin shell.
     */
    function syncGeometry() {
        const header = document.querySelector('.site-header');
        const sidebar = shell.querySelector('[data-admin-navigation]');
        if (!(sidebar instanceof HTMLElement)) return;
        const headerHeight = header instanceof HTMLElement
            ? header.getBoundingClientRect().height : 0;
        shell.style.setProperty(
            '--admin-navigation-header-height', `${headerHeight}px`,
        );
        const panel = sidebar.querySelector('.admin-navigation-links');
        const panelTop = panel instanceof HTMLElement && !panel.hidden
            ? panel.getBoundingClientRect().top
            : sidebar.getBoundingClientRect().bottom;
        const availableHeight = Math.max(
            0, window.innerHeight - panelTop - VIEWPORT_EDGE_GAP_PX,
        );
        shell.style.setProperty(
            '--admin-navigation-panel-height', `${availableHeight}px`,
        );
    }

    /**
     * Apply current state to the original or freshly replaced sidebar.
     * @return {void} Synchronizes labels, panel visibility and responsive layout.
     */
    function render() {
        const sidebar = shell.querySelector('[data-admin-navigation]');
        const button = sidebar?.querySelector('[data-admin-navigation-toggle]');
        const panel = sidebar?.querySelector('.admin-navigation-links');
        if (!(sidebar instanceof HTMLElement)
            || !(button instanceof HTMLButtonElement)
            || !(panel instanceof HTMLElement)) return;
        shell.classList.add('admin-navigation-ready');
        shell.classList.toggle('admin-navigation-collapsed', collapsed);
        shell.classList.toggle('admin-navigation-open', mobile.matches && open);
        button.hidden = false;
        const label = mobile.matches
            ? (open ? button.dataset.closeLabel : button.dataset.openLabel)
            : (collapsed ? button.dataset.expandLabel : button.dataset.collapseLabel);
        button.setAttribute('aria-label', label || '');
        button.title = label || '';
        if (mobile.matches) button.setAttribute('aria-expanded', String(open));
        else button.removeAttribute('aria-expanded');
        panel.hidden = mobile.matches && !open;
        if (observedSidebar !== sidebar) {
            selectionObserver.disconnect();
            selectionObserver.observe(sidebar, {
                attributes: true, attributeFilter: ['class'], subtree: true,
            });
            observedSidebar = sidebar;
        }
        syncCurrentPage();
        syncGeometry();
    }

    /**
     * Dismiss a mobile disclosure while preserving keyboard orientation.
     * @param {boolean} restoreFocus Whether dismissal should focus its trigger.
     * @return {void} Closes the panel and optionally restores trigger focus.
     */
    function close(restoreFocus) {
        if (!open) return;
        open = false;
        render();
        if (restoreFocus) {
            shell.querySelector('[data-admin-navigation-toggle]')?.focus();
        }
    }

    // Delegate to the shell so Settings can replace the sidebar in place.
    shell.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) return;
        const toggle = event.target.closest('[data-admin-navigation-toggle]');
        if (toggle) {
            if (mobile.matches) open = !open;
            else {
                collapsed = !collapsed;
                try {
                    window.localStorage.setItem(
                        STORAGE_KEY, collapsed ? 'collapsed' : 'expanded',
                    );
                } catch {
                    // Same permanent storage-denial fallback as initial reading:
                    // retain this session's choice without blocking navigation.
                }
            }
            render();
        } else if (mobile.matches
            && event.target.closest('.admin-menu-link')) {
            // Hash destinations can stay in this document; do not strand focus
            // inside their now-hidden navigation after activating a link.
            close(true);
        }
    });
    document.addEventListener('click', (event) => {
        if (!mobile.matches || !open || !(event.target instanceof Node)) return;
        const sidebar = shell.querySelector('[data-admin-navigation]');
        if (sidebar?.contains(event.target)) return;
        close(sidebar?.contains(document.activeElement) === true);
    });
    document.addEventListener('keydown', (event) => {
        if (mobile.matches && open && event.key === 'Escape') {
            event.preventDefault();
            close(true);
        }
    });
    document.addEventListener('focusin', (event) => {
        const sidebar = shell.querySelector('[data-admin-navigation]');
        if (mobile.matches && open && event.target instanceof Node
            && !sidebar?.contains(event.target)) close(false);
    });
    mobile.addEventListener('change', () => {
        const panel = shell.querySelector('.admin-navigation-links');
        const focusWasInside = panel?.contains(document.activeElement);
        open = false;
        render();
        if (mobile.matches && focusWasInside) {
            shell.querySelector('[data-admin-navigation-toggle]')?.focus();
        }
    });
    window.addEventListener('storage', (event) => {
        if (event.key !== STORAGE_KEY && event.key !== null) return;
        try {
            if (event.storageArea !== window.localStorage) return;
        } catch {
            // Storage can become unavailable after initialization; the same
            // navigation-owned in-memory fallback must remain usable.
            return;
        }
        collapsed = event.newValue === 'collapsed';
        render();
    });
    window.addEventListener('resize', syncGeometry);
    window.addEventListener('scroll', () => {
        if (mobile.matches && open) syncGeometry();
    }, {passive: true});
    new MutationObserver(() => render()).observe(shell, {childList: true});
    const header = document.querySelector('.site-header');
    // Header wrapping can change independently of window size. Older browsers
    // without ResizeObserver retain initial/resize geometry permanently; the
    // navigation owner must preserve this until the browser support floor moves.
    if (header && typeof ResizeObserver === 'function') {
        new ResizeObserver(syncGeometry).observe(header);
    }
    render();
}
