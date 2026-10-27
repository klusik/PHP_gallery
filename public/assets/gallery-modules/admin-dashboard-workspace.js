/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-dashboard-workspace.js
 * Module Type: Browser Module
 * Purpose: Defer dashboard reads and save compact Maintenance preferences in place.
 * Responsibilities: Bind fragments, serialize preference autosave and initialize gallery controls.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * Load read-only Overview totals after paint and gallery previews only when their tab is opened.
 * Existing tab/bulk modules and authenticated domain routes retain their authority.
 */
import { setupAdminGalleryFilters, setupAdminGalleryTree, setupAdminGalleryReordering } from './admin-gallery-list.js?v=20261002-gallery-tree-v3';
import { setupAdminGalleryFeatures } from './admin-gallery-features.js?v=20261021-smart-photo-tags-v2';
import { setupAdminGalleryQuickAccess } from './admin-gallery-quick-access.js?v=20261021-smart-photo-tags-v2';
import { setupAdminCacheCleanup } from './admin-cache-cleanup.js?v=20261021-smart-photo-tags-v2';
import { completeAdminMutation } from './admin-mutation-completion.js?v=20261021-smart-photo-tag-refresh-v1';

/**
 * Bound one preference write before requiring an explicit authoritative reload.
 * Type: number. Units: milliseconds. Scope: one Maintenance autosave request.
 * Consumers: saveDashboardContentForm(). Value: 15000.
 * Rationale: match Features autosave; never retry an uncertain mutation.
 */
const DASHBOARD_CONTENT_SAVE_TIMEOUT_MS = 15000;

/**
 * Show a compact save state or an explicit reload action after an unverified write.
 * @param {HTMLFormElement} form Existing preference form; never a bulk reset.
 * @param {'saving'|'saved'|'error'|'unknown'} state Observable persistence state.
 * @param {string} message Localized status or safe server refusal.
 * @return {void} Update only this form's feedback, preserving controls and help.
 */
function setDashboardContentSaveStatus(form, state, message) {
    const owner = form.closest('[data-dashboard-content-settings]');
    let feedback = form.querySelector('[data-dashboard-save-status]');
    if (!feedback) {
        feedback = document.createElement('span');
        feedback.dataset.dashboardSaveStatus = '';
        feedback.setAttribute('role', 'status');
        feedback.setAttribute('aria-live', 'polite');
    }
    form.dataset.saveState = state;
    feedback.textContent = message;
    if (state === 'error' || state === 'unknown') {
        const reload = document.createElement('button');
        reload.type = 'button';
        reload.textContent = owner.dataset.reloadLabel;
        reload.addEventListener('click', () => window.location.reload());
        feedback.append(' ', reload);
        form.append(feedback);
    } else {
        (form.querySelector('.admin-content-controls') || form).append(feedback);
    }
}

/**
 * Save one preference form once and reconcile authoritative returned values.
 * @param {HTMLFormElement} form Authenticated route/CSRF form containing Content
 *     booleans or the coupled schedule booleans, time and numeric fields.
 * @return {Promise<void>} Keep URL/DOM stable; lock uncertain/refused writes until reload.
 */
async function saveDashboardContentForm(form) {
    if (['saving', 'error', 'unknown'].includes(form.dataset.saveState)) return;
    const owner = form.closest('[data-dashboard-content-settings]');
    if (!form.reportValidity()) return;
    const controls = Array.from(form.querySelectorAll(
        'input:not([type="hidden"]):not([type="submit"]):not(:disabled)',
    ));
    const body = new FormData(form);
    body.set('ajax', '1');
    controls.forEach(control => { control.disabled = true; });
    form.setAttribute('aria-busy', 'true');
    setDashboardContentSaveStatus(form, 'saving', owner.dataset.savingLabel);
    try {
        const response = await fetch(form.getAttribute('action'), {
            method: 'POST', body, credentials: 'same-origin',
            cache: 'no-store', redirect: 'error',
            headers: {'Accept': 'application/json'},
            signal: AbortSignal.timeout(DASHBOARD_CONTENT_SAVE_TIMEOUT_MS),
        });
        const result = await response.json();
        if (typeof result.ok !== 'boolean'
            || typeof result.message !== 'string'
            || !result.setting_values
            || controls.some(control => typeof result.setting_values[control.name]
                !== (control.type === 'checkbox' ? 'boolean' : 'string'))
            || (!response.ok && result.ok)) {
            throw new Error('Incomplete dashboard preference response');
        }
        for (const control of controls) {
            if (control.type === 'checkbox') control.checked = result.setting_values[control.name];
            else control.value = result.setting_values[control.name];
        }
        if (!result.ok) {
            setDashboardContentSaveStatus(form, 'error', result.message);
            return;
        }
        await completeAdminMutation(result);
        if (form.hasAttribute('data-dashboard-schedule-preferences')) {
            const group = form.closest('.admin-site-maintenance-card');
            const state = group?.querySelector('[data-dashboard-schedule-state]');
            const time = group?.querySelector('[data-dashboard-schedule-time]');
            if (state) state.textContent = result.setting_values.site_maintenance_enabled
                ? state.dataset.enabledLabel : state.dataset.disabledLabel;
            if (time) time.textContent = result.setting_values.site_maintenance_utc_time;
        }
        setDashboardContentSaveStatus(form, 'saved', owner.dataset.savedLabel);
        controls.forEach(control => { control.disabled = false; });
    } catch {
        // A lost response may follow a successful write. Preserve the displayed
        // intent and require a passive reload instead of replay or fake rollback.
        setDashboardContentSaveStatus(form, 'unknown', owner.dataset.failedLabel);
    } finally {
        form.removeAttribute('aria-busy');
    }
}

/**
 * Enhance declared preference forms; thumbnail options and bulk tasks stay explicit.
 * @param {HTMLElement} root Dashboard or a newly inserted Maintenance read region.
 * @return {void} Mark autosave forms and expose factory tooltips on their full labels.
 */
function setupDashboardContentAutosave(root) {
    for (const form of root.querySelectorAll(
        '[data-dashboard-content-settings] form:is(.admin-content-row, [data-dashboard-schedule-preferences])',
    )) {
        const controls = form.querySelectorAll('input[type="checkbox"]');
        if (!controls.length || form.dataset.dashboardContentAutosave) continue;
        form.dataset.dashboardContentAutosave = '1';
        for (const control of controls) {
            if (control.title) {
                control.closest('label').title = control.title;
                control.setAttribute('aria-description', control.title);
            }
        }
    }
}

/**
 * Purpose: Bound one read so a stalled connection exposes an explicit retry.
 * Units: milliseconds.
 * Scope: one Overview or Galleries read request.
 * Consumers: setupAdminDashboardWorkspace/load.
 * Rationale: twenty seconds tolerates a cold installation while keeping page actions usable.
 * Type: number.
 * @type {number}
 */
const DASHBOARD_READ_TIMEOUT_MS = 20000;

/**
 * Keep Maintenance help clear of viewport edges beside its trigger.
 * Type: number. Units: CSS pixels. Scope: dashboard contextual help.
 * Consumers: positionDashboardHelp(). Value: 8.
 * Rationale: match the compact Features panel's readable edge and trigger gap.
 */
const DASHBOARD_HELP_GAP_PX = 8;

/**
 * Position one open help panel without moving its trigger or surrounding rows.
 * @param {HTMLElement} panel Open native popover containing escaped help prose.
 * @param {HTMLElement} trigger Owning question-mark button or native summary.
 * @return {void} Fit the panel beside the trigger within the current viewport.
 */
function positionDashboardHelp(panel, trigger) {
    const rect = trigger.getBoundingClientRect();
    const gap = DASHBOARD_HELP_GAP_PX;
    // The client box excludes an occupied scrollbar; innerWidth can place help
    // underneath it even when the document itself has no horizontal overflow.
    const width = document.documentElement.clientWidth;
    const height = document.documentElement.clientHeight;
    panel.style.maxWidth = `${width - gap - gap}px`;
    const below = rect.bottom + gap;
    const top = below + panel.offsetHeight <= height - gap
        ? below : rect.top - panel.offsetHeight - gap;
    panel.style.left = `${Math.max(gap, Math.min(
        rect.left, width - panel.offsetWidth - gap,
    ))}px`;
    panel.style.top = `${Math.max(gap, Math.min(
        top, height - panel.offsetHeight - gap,
    ))}px`;
}

/**
 * Enhance only the Maintenance fragment's help and compact action labels.
 * @param {HTMLElement} root Dashboard or newly inserted Maintenance read region.
 * @return {void} Preserve native disclosures without popover support and every form field.
 */
function setupDashboardCompactHelp(root) {
    // Permanent progressive enhancement: the server's native disclosure and
    // text actions remain usable without JavaScript or Popover API support.
    if (typeof HTMLElement.prototype.showPopover !== 'function') return;
    for (const action of root.querySelectorAll(
        '#admin-maintenance-content .admin-content-actions > button[aria-label], '
        + '#admin-maintenance-content .admin-content-actions > a:not(.button)',
    )) {
        const label = action.getAttribute('aria-label') || action.textContent.trim();
        action.setAttribute('aria-label', label);
        action.title = label;
        action.dataset.dashboardCompactAction = action.tagName === 'BUTTON'
            ? 'save' : 'settings';
    }
    for (const disclosure of root.querySelectorAll('[data-dashboard-help]')) {
        const summary = disclosure.querySelector('summary');
        const panel = disclosure.querySelector('.admin-dashboard-help-panel');
        if (!summary || !panel?.id) continue;
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'admin-dashboard-help-trigger';
        trigger.textContent = '?';
        trigger.setAttribute('aria-label', summary.getAttribute('aria-label'));
        trigger.setAttribute('aria-controls', panel.id);
        trigger.setAttribute('popovertarget', panel.id);
        panel.setAttribute('popover', 'auto');
        disclosure.replaceWith(trigger, panel);
    }
}

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
 * Attach deferred reads and contextual help without polling or running maintenance.
 * @return {void} Bind one workspace, enhance loaded help and retain fragments across tab changes.
 */
export function setupAdminDashboardWorkspace() {
    setupAdminCacheCleanup();
    const root = document.querySelector('[data-admin-dashboard-workspace]');
    if (!(root instanceof HTMLElement) || root.dataset.dashboardBound === '1') return;
    root.dataset.dashboardBound = '1';
    setupDashboardContentAutosave(root);
    setupDashboardCompactHelp(root);
    root.addEventListener('change', event => {
        const control = event.target;
        if (!(control instanceof HTMLInputElement)) return;
        const form = control.closest('form[data-dashboard-content-autosave]');
        if (!form) return;
        event.stopImmediatePropagation();
        void saveDashboardContentForm(form);
    }, true);
    root.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.dataset.dashboardContentAutosave) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        void saveDashboardContentForm(form);
    }, true);
    // Delegation also covers newly fetched Maintenance fragments. Native
    // popovers own outside-click/Escape dismissal and keyboard focus return.
    root.addEventListener('toggle', event => {
        const panel = event.target;
        // Cache snapshots replace their overview in place. Enhance each fresh
        // native disclosure on opening instead of replacing its owned markup.
        if (panel instanceof HTMLDetailsElement
            && panel.hasAttribute('data-cache-help')
            && typeof HTMLElement.prototype.showPopover === 'function') {
            const body = panel.querySelector('div');
            const summary = panel.querySelector('summary');
            if (!body || !summary) return;
            body.classList.add('admin-dashboard-help-panel');
            body.setAttribute('popover', 'auto');
            if (panel.open) {
                body.showPopover();
                positionDashboardHelp(body, summary);
            } else body.hidePopover();
            return;
        }
        if (panel instanceof HTMLElement && panel.hasAttribute('popover')
            && panel.closest('[data-cache-help]')
            && !panel.matches(':popover-open')) {
            panel.closest('[data-cache-help]').open = false;
        }
        if (!(panel instanceof HTMLElement)
            || !panel.classList.contains('admin-dashboard-help-panel')
            || !panel.hasAttribute('popover')
            || !panel.matches(':popover-open')) return;
        const trigger = panel.closest('[data-cache-help]')?.querySelector('summary')
            || root.querySelector(
            `[popovertarget="${CSS.escape(panel.id)}"]`,
        );
        if (trigger instanceof HTMLElement) positionDashboardHelp(panel, trigger);
    }, true);
    const repositionHelp = () => {
        for (const panel of root.querySelectorAll('.admin-dashboard-help-panel[popover]')) {
            if (!panel.matches(':popover-open')) continue;
            const trigger = panel.closest('[data-cache-help]')?.querySelector('summary')
                || root.querySelector(`[popovertarget="${CSS.escape(panel.id)}"]`);
            if (trigger instanceof HTMLElement) positionDashboardHelp(panel, trigger);
        }
    };
    window.addEventListener('resize', repositionHelp);
    document.addEventListener('scroll', repositionHelp, true);
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const help = root.querySelector('[data-cache-help][open]');
        const panel = help?.querySelector('[popover]');
        if (!panel?.matches(':popover-open')) return;
        // Passive snapshots can replace a formerly focused summary. Restore
        // focus to the current trigger rather than to that detached node.
        panel.hidePopover();
        help.open = false;
        help.querySelector('summary')?.focus();
    });
    // Maintenance owns an explicit, allowlisted query while generic nested tabs remain local.
    const maintenanceIds = new Map(['content','media','navigation','trash','system'].map(value => ['admin-maintenance-' + value,value]));
    let restoringMaintenance = false;
    const rememberMaintenance = event => {
        if (restoringMaintenance || !(event.target instanceof Element)) return;
        const scope = event.target.closest('.admin-dashboard-maintenance-scope');
        if (!scope || !event.target.closest('[data-admin-subtab-target]')) return;
        if (event.type === 'keydown' && !['ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Home','End','Enter',' '].includes(event.key)) return;
        const selected = scope.querySelector('[data-admin-subtab-target][aria-selected="true"]');
        const value = maintenanceIds.get(selected?.dataset.adminSubtabTarget);
        if (!value) return;
        const url = new URL(location.href);
        url.searchParams.set('dashboard_tab', 'maintenance');
        url.searchParams.set('maintenance_tab', value);
        url.hash = 'admin-tab-maintenance';
        const primary = root.querySelector('[data-admin-tab-target="admin-tab-maintenance"]');
        if (primary instanceof HTMLAnchorElement) primary.href = url.href;
        if (url.href !== location.href) history.pushState(null, '', url);
    };
    root.addEventListener('click', rememberMaintenance);
    root.addEventListener('keydown', rememberMaintenance);
    window.addEventListener('popstate', () => {
        const value = new URL(location.href).searchParams.get('maintenance_tab') || 'content';
        const primary = root.querySelector('[data-admin-tab-target="admin-tab-maintenance"]');
        if (primary instanceof HTMLAnchorElement && location.hash === '#admin-tab-maintenance') primary.href = location.href;
        const id = [...maintenanceIds].find(([,candidate]) => candidate === value)?.[0];
        const tab = id ? root.querySelector('.admin-dashboard-maintenance-scope [data-admin-subtab-target="' + id + '"]') : null;
        if (tab && tab.getAttribute('aria-selected') !== 'true') {
            restoringMaintenance = true;
            try { tab.click(); } finally { restoringMaintenance = false; }
        }
    });

    // A deferred Maintenance fragment changes document height after native reload restoration.
    // Align its explicit URL anchor once after both page load and fragment insertion; polling never scrolls.
    if (location.hash === '#admin-tab-maintenance') {
        const panel = root.querySelector('#admin-tab-maintenance');
        let pageLoaded = document.readyState === 'complete';
        let aligned = false;
        const observer = new MutationObserver(() => alignMaintenance());
        const alignMaintenance = () => {
            if (aligned || !pageLoaded || !panel?.querySelector('.admin-dashboard-maintenance-scope')) return;
            aligned = true;
            observer.disconnect();
            requestAnimationFrame(() => {
                if (location.hash === '#admin-tab-maintenance') panel.scrollIntoView({block:'start',behavior:'instant'});
            });
        };
        if (panel) observer.observe(panel, {childList:true,subtree:true});
        window.addEventListener('load', () => { pageLoaded = true; alignMaintenance(); }, {once:true});
        alignMaintenance();
    }

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
            if (region.dataset.dashboardRead === 'maintenance') {
                setupDashboardContentAutosave(region);
                setupDashboardCompactHelp(region);
            }
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
