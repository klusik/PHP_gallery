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
import { setupAdminGalleryFilters, setupAdminGalleryTree, setupAdminGalleryReordering } from './admin-gallery-list.js?v=20261002-gallery-tree-v3';
import { setupAdminGalleryFeatures } from './admin-gallery-features.js?v=20261002-gallery-controls-v3';
import { setupAdminGalleryQuickAccess } from './admin-gallery-quick-access.js?v=20261002-gallery-controls-v3';

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
    const refreshAgain = new WeakSet();
    let galleryGestureActive = false;
    for (const region of reads) {
        if (region.dataset.dashboardLoaded === '1') states.set(region, 'loaded');
    }
    setupAdminGalleryFeatures();
    setupAdminGalleryQuickAccess();

    /**
     * Describe the current displayed hierarchy without consulting storage.
     * @param {HTMLElement} region Galleries region.
     * @return {string} Ordered row/parent signature used to protect an in-progress move.
     */
    function hierarchySignature(region) {
        return Array.from(region.querySelectorAll('[data-gallery-row]'),
            /** Serialize a current row's parent relationship. @param {HTMLElement} row Current tree row. @return {string} Stable hierarchy pair. */ row => `${row.dataset.galleryId}:${row.dataset.parentId}`).join('|');
    }

    /**
     * Prevent read completion from replacing an active gesture or unsaved tree.
     * @param {HTMLElement} region Galleries region.
     * @return {boolean} Whether the current tree must retain its DOM.
     */
    function treeNeedsProtection(region) {
        return region.dataset.dashboardRead === 'galleries' && (galleryGestureActive || Boolean(region.querySelector('.is-dragging'))
            || ['saving', 'dragging', 'error'].includes(region.querySelector('[data-admin-gallery-order-status]')?.dataset.state));
    }

    /**
     * Retain local filter and bulk selections while replacing acknowledged gallery metadata.
     * @param {HTMLElement} region Stable Galleries read owner.
     * @param {string} html Authoritative replacement markup.
     * @return {void} Initializes new controls without selecting filtered-out galleries.
     */
    function replaceGalleries(region, html) {
        const filterValue = region.querySelector('[data-gallery-visibility-filter]')?.value || 'all';
        const bulkAction = region.querySelector('select[name="action"]')?.value || '';
        const selected = new Set(Array.from(region.querySelectorAll('input[name="gallery_ids[]"]:checked'),
            /** Preserve one explicitly selected row identity. @param {HTMLInputElement} input Selected gallery checkbox. @return {string} Stable row identity. */ input => input.value));
        region.innerHTML = html;
        const filter = region.querySelector('[data-gallery-visibility-filter]');
        if (filter instanceof HTMLSelectElement) filter.value = filterValue;
        const action = region.querySelector('select[name="action"]');
        if (action instanceof HTMLSelectElement && Array.from(action.options).some(
            /** Check that the prior bulk operation remains available. @param {HTMLOptionElement} option Available bulk operation. @return {boolean} Whether it matches prior intent. */ option => option.value === bulkAction)) action.value = bulkAction;
        setupAdminGalleryFilters();
        setupAdminGalleryTree();
        setupAdminGalleryReordering();
        for (const input of region.querySelectorAll('input[name="gallery_ids[]"]')) {
            if (input.closest('[data-gallery-row]')?.dataset.hiddenByFilter !== '1') input.checked = selected.has(input.value);
        }
        setupAdminGalleryFeatures();
        setupAdminGalleryQuickAccess();
    }

    /**
     * Fill one visible read region; concurrent activations share its pending request.
     * @param {HTMLElement} region Placeholder owned by this loader.
     * @return {Promise<void>} Resolves after rendering or showing an explicit retry.
     */
    async function load(region) {
        const panel = region.closest('[data-admin-tab-panel]');
        const state = states.get(region);
        if (!panel || panel.hidden || !panel.classList.contains('is-active') || state === 'loading' || state === 'loaded' || state === 'failed') return;
        if (treeNeedsProtection(region)) return;
        const retainContent = region.dataset.dashboardLoaded === '1';
        const signature = hierarchySignature(region);
        states.set(region, 'loading');
        region.setAttribute('aria-busy', 'true');
        const controller = new AbortController();
        const timeout = window.setTimeout(/** Abort a stalled read while retaining usable page actions. @return {void} Cancels this request only. */ () => controller.abort(), DASHBOARD_READ_TIMEOUT_MS);
        try {
            const response = await fetch(region.dataset.endpoint, {credentials: 'same-origin', headers: {Accept: 'application/json'}, signal: controller.signal});
            if (!response.ok) throw new Error('Dashboard read unavailable');
            const result = await response.json();
            if (result.ok !== true || typeof result.html !== 'string' || !result.html.trim()) throw new Error('Invalid dashboard fragment');
            // A mutation acknowledged during this read invalidates its result before presentation.
            if (refreshAgain.has(region)) return;
            if (treeNeedsProtection(region) || hierarchySignature(region) !== signature) {
                refreshAgain.add(region);
                return;
            }
            if (region.dataset.dashboardRead === 'galleries') replaceGalleries(region, result.html);
            else region.innerHTML = result.html;
            states.set(region, 'loaded');
            region.dataset.dashboardLoaded = '1';
            updateGalleryCount(root, result.total_galleries);
        } catch {
            if (refreshAgain.has(region)) return;
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
            if (retainContent) {
                region.querySelector('[data-dashboard-read-error]')?.remove();
                const notice = document.createElement('div');
                notice.dataset.dashboardReadError = '1';
                notice.append(message, retry);
                region.prepend(notice);
            } else region.replaceChildren(message, retry);
        } finally {
            window.clearTimeout(timeout);
            region.removeAttribute('aria-busy');
            if (refreshAgain.has(region)) {
                refreshAgain.delete(region);
                states.delete(region);
                void load(region);
            }
        }
    }

    /**
     * Refresh only owned Admin metadata after a confirmed persistent mutation.
     * @return {void} Invalidates the read while retaining stable local feature drafts.
     */
    function refreshGalleries() {
        const region = reads.find(/** Select the stable owner of gallery metadata. @param {HTMLElement} candidate Read surface. @return {boolean} Whether it owns gallery metadata. */ candidate => candidate.dataset.dashboardRead === 'galleries');
        if (!region) return;
        if (states.get(region) === 'loading') refreshAgain.add(region);
        else {
            states.delete(region);
            void load(region);
        }
    }
    document.addEventListener('adminGalleryWorkspaceMutation', refreshGalleries);
    document.addEventListener('adminGalleryOrderSaved', refreshGalleries);

    document.addEventListener('pointerdown', /** Track even a pointer candidate below the drag threshold. @param {PointerEvent} event Native interaction. @return {void} Protects current hierarchy markup. */ event => {
        if (event.button === 0 && event.target instanceof Element && event.target.closest('[data-admin-gallery-drag-zone]')?.closest('[data-dashboard-read="galleries"]')) galleryGestureActive = true;
    }, true);
    if (!window.PointerEvent) document.addEventListener('mousedown', /** Track the existing mouse-only drag fallback. @param {MouseEvent} event Native interaction. @return {void} Protects current hierarchy markup. */ event => {
        if (event.button === 0 && event.target instanceof Element && event.target.closest('[data-admin-gallery-drag-zone]')?.closest('[data-dashboard-read="galleries"]')) galleryGestureActive = true;
    }, true);
    /** Release a candidate after existing tree handlers finish cancellation or saving.
     * @return {void} Schedules only previously invalidated reads.
     */
    function releaseGalleryGesture() {
        galleryGestureActive = false;
        scheduleVisibleReads();
    }
    document.addEventListener('pointerup', releaseGalleryGesture);
    document.addEventListener('pointercancel', releaseGalleryGesture);
    if (!window.PointerEvent) document.addEventListener('mouseup', releaseGalleryGesture);
    document.addEventListener('keydown', /** Resume pending metadata only after the existing Escape cancellation. @param {KeyboardEvent} event Native key. @return {void} Leaves other keys untouched. */ event => {
        if (event.key === 'Escape') releaseGalleryGesture();
    });

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
