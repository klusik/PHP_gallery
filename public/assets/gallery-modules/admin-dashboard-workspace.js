/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-dashboard-workspace.js
 * Module Type: Browser Module
 * Purpose: Defer dashboard metadata and gallery previews until their visible surface needs them.
 * Responsibilities: Bind read-only fragments, retry failed reads and initialize the existing gallery controls.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * Load read-only Overview totals after paint and gallery previews only when their tab is opened.
 * The existing tab, bulk-action and mutation modules retain ownership of navigation and writes.
 */
import { setupAdminGalleryFilters, setupAdminGalleryTree, setupAdminGalleryReordering } from './admin-gallery-list.js?v=20260519-public-drop-refactor-v1';

/**
 * Purpose: Bound one read so a stalled connection exposes an explicit retry.
 * Units: milliseconds.
 * Scope: one Overview or Galleries read request.
 * Consumers: setupAdminDashboardWorkspace/load.
 * Rationale: twenty seconds tolerates a cold installation while keeping page actions usable.
 * @type {number}
 */
const DASHBOARD_READ_TIMEOUT_MS = 20000;

/**
 * Update the Galleries tab count from an authenticated, completed read.
 * @param {HTMLElement} root Dashboard workspace.
 * @param {number} count Verified non-negative integer count.
 * @return {void} Updates or creates a text-only badge.
 */
function updateGalleryCount(root, count) {
    if (!Number.isSafeInteger(count) || count < 0) return;
    const tab = root.querySelector('[data-admin-tab-target="admin-tab-galleries"]');
    if (!tab) return;
    let badge = tab.querySelector('.admin-tab-badge');
    if (!badge) {
        badge = document.createElement('span');
        badge.className = 'admin-tab-badge';
        tab.append(badge);
    }
    badge.textContent = String(count);
}

/**
 * Attach deferred reads to the current dashboard without polling or running maintenance.
 * @return {void} Binds a single workspace and keeps successful fragments resident across tab changes.
 */
export function setupAdminDashboardWorkspace() {
    const root = document.querySelector('[data-admin-dashboard-workspace]');
    if (!(root instanceof HTMLElement) || root.dataset.dashboardBound === '1') return;
    root.dataset.dashboardBound = '1';
    const reads = Array.from(root.querySelectorAll('[data-dashboard-read]'));
    const states = new WeakMap();

    /**
     * Fill one visible read region; concurrent activations share its pending request.
     * @param {HTMLElement} region Placeholder owned by this loader.
     * @return {Promise<void>} Resolves after rendering or showing an explicit retry.
     */
    async function load(region) {
        const panel = region.closest('[data-admin-tab-panel]');
        const state = states.get(region);
        if (!panel || panel.hidden || !panel.classList.contains('is-active') || state === 'loading' || state === 'loaded' || state === 'failed') return;
        states.set(region, 'loading');
        region.setAttribute('aria-busy', 'true');
        const controller = new AbortController();
        const timeout = window.setTimeout(/** Abort a stalled read while retaining usable page actions. @return {void} Cancels this request only. */ () => controller.abort(), DASHBOARD_READ_TIMEOUT_MS);
        try {
            const response = await fetch(region.dataset.endpoint, {credentials: 'same-origin', headers: {Accept: 'application/json'}, signal: controller.signal});
            if (!response.ok) throw new Error('Dashboard read unavailable');
            const result = await response.json();
            if (result.ok !== true || typeof result.html !== 'string' || !result.html.trim()) throw new Error('Invalid dashboard fragment');
            region.innerHTML = result.html;
            states.set(region, 'loaded');
            updateGalleryCount(root, result.total_galleries);
            if (region.dataset.dashboardRead === 'galleries') {
                setupAdminGalleryFilters();
                setupAdminGalleryTree();
                setupAdminGalleryReordering();
            }
        } catch {
            states.set(region, 'failed');
            const message = document.createElement('p');
            message.className = 'muted';
            message.setAttribute('role', 'status');
            message.textContent = region.dataset.error;
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'secondary';
            retry.textContent = region.dataset.retry;
            retry.addEventListener('click', /** Retry only this failed read without navigation or other calculations. @return {void} Restarts the owned request. */ () => {
                states.delete(region);
                retry.disabled = true;
                void load(region);
            });
            region.replaceChildren(message, retry);
        } finally {
            window.clearTimeout(timeout);
            region.removeAttribute('aria-busy');
        }
    }

    /**
     * Allow the active tab and its primary actions to paint before starting any read.
     * @return {void} Schedules visible, previously unrequested regions only.
     */
    function scheduleVisibleReads() {
        requestAnimationFrame(/** Wait for the first paint boundary. @return {void} Schedules the read after the next frame. */ () => {
            requestAnimationFrame(/** Start reads for the currently visible tab. @return {void} Leaves hidden regions untouched. */ () => {
                for (const region of reads) void load(region);
            });
        });
    }
    const observer = new MutationObserver(scheduleVisibleReads);
    for (const panel of root.querySelectorAll('[data-admin-tab-panel]')) {
        observer.observe(panel, {attributes: true, attributeFilter: ['hidden']});
    }
    scheduleVisibleReads();
}
