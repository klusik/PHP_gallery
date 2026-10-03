/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/theme-appearance-resizer.js
 * Module Type: Browser Module
 * Purpose: Resize the existing Appearance settings and preview without changing theme preferences.
 * Responsibilities:
 *   - Bound pointer and keyboard resizing by the two pane minimum widths
 *   - Restore an installation-scoped local layout ratio after viewport changes
 *   - Keep separator accessibility and transient pointer listeners synchronized
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */

/** @type {WeakSet<HTMLElement>} Workspaces with one installed local resize controller. */
const boundWorkspaces = new WeakSet();

/**
 * Bind the compact Appearance separator to its existing settings and preview panes.
 * @param {HTMLFormElement} form Theme form containing the optional Appearance workspace.
 * @return {void} Installs a local layout preference once; stacked layouts remain unaffected.
 */
export function setupThemeAppearanceResize(form) {
    const workspace = form.querySelector('.theme-appearance-workspace');
    const handle = workspace?.querySelector('[data-theme-appearance-resizer]');
    const settings = workspace?.querySelector('.theme-appearance-settings');
    if (!(workspace instanceof HTMLElement) || !(handle instanceof HTMLElement) || !(settings instanceof HTMLElement) || boundWorkspaces.has(workspace)) {
        return;
    }
    boundWorkspaces.add(workspace);
    const storageKey = 'php-gallery:theme-appearance-width:' + window.location.pathname;
    let preferredRatio = null;
    try {
        const stored = window.localStorage.getItem(storageKey);
        const ratio = stored === null ? NaN : Number(stored);
        if (Number.isFinite(ratio) && ratio > 0 && ratio < 1) preferredRatio = ratio;
    } catch {
        // Storage restrictions must not prevent resizing during the current page visit.
    }
    let pointerId = null;
    let pointerStartX = 0;
    let pointerStartWidth = 0;
    let pointerStartRatio = null;
    let observer = null;

    /**
     * Measure the visible grid and reserve its actual divider and two column gaps.
     * @return {{width:number,minimum:number,maximum:number,available:number}|null} Feasible horizontal pane bounds, or null when stacked/hidden.
     */
    function bounds() {
        if (!workspace.isConnected || handle.hidden || handle.getClientRects().length === 0) return null;
        const style = getComputedStyle(workspace);
        const width = workspace.clientWidth - (parseFloat(style.paddingLeft) || 0) - (parseFloat(style.paddingRight) || 0);
        const gap = parseFloat(style.columnGap) || 0;
        const available = width - handle.getBoundingClientRect().width - 2 * gap;
        if (available < 700) return null;
        return {width, minimum: 360, maximum: available - 340, available};
    }

    /**
     * Render a clamped pane width and consistent pixel-based separator values.
     * @param {number} width Requested settings width in pixels.
     * @param {boolean} remember Whether this user action replaces the preferred viewport ratio.
     * @return {void} Changes only local CSS and accessible resize state.
     */
    function setWidth(width, remember) {
        const limits = bounds();
        if (!limits) return;
        const next = Math.max(limits.minimum, Math.min(limits.maximum, width));
        if (remember) preferredRatio = next / limits.available;
        const cssWidth = next.toFixed(2) + 'px';
        if (workspace.style.getPropertyValue('--theme-appearance-controls-width') !== cssWidth) {
            workspace.style.setProperty('--theme-appearance-controls-width', cssWidth);
        }
        const settingsPercent = Math.round(next / limits.available * 100);
        const template = handle.dataset.themeResizeValue || '{settings}% settings / {preview}% preview';
        handle.setAttribute('aria-valuemin', String(Math.ceil(limits.minimum)));
        handle.setAttribute('aria-valuemax', String(Math.floor(limits.maximum)));
        handle.setAttribute('aria-valuenow', String(Math.round(next)));
        handle.setAttribute('aria-valuetext', template.replaceAll('{settings}', String(settingsPercent)).replaceAll('{preview}', String(100 - settingsPercent)));
        handle.setAttribute('aria-disabled', 'false');
        handle.tabIndex = 0;
    }

    /**
     * Persist only an explicit local ratio, or remove the key when restoring the CSS default.
     * @return {void} Tolerates blocked browser storage without affecting Theme submission.
     */
    function persist() {
        try {
            if (preferredRatio === null) window.localStorage.removeItem(storageKey);
            else window.localStorage.setItem(storageKey, String(preferredRatio));
        } catch {
            // The visible layout remains usable when local storage is unavailable.
        }
    }

    /**
     * Remove transient drag ownership and restore the prior ratio for a cancelled gesture.
     * @param {boolean} commit Whether the finished pointer gesture should persist its ratio.
     * @return {void} Releases capture and document listeners without retaining a detached workspace.
     */
    function finishDrag(commit) {
        if (pointerId === null) return;
        const releasedId = pointerId;
        pointerId = null;
        document.removeEventListener('pointermove', movePointer, true);
        document.removeEventListener('pointerup', endPointer, true);
        document.removeEventListener('pointercancel', cancelPointer, true);
        workspace.classList.remove('is-resizing');
        if (handle.hasPointerCapture?.(releasedId)) handle.releasePointerCapture(releasedId);
        if (commit) persist();
        else { preferredRatio = pointerStartRatio; refresh(); }
    }

    /**
     * Apply only the owned pointer movement to the measured starting settings width.
     * @param {PointerEvent} event Pointer movement during separator dragging.
     * @return {void} Prevents text selection only for this active resize gesture.
     */
    function movePointer(event) {
        if (event.pointerId !== pointerId) return;
        event.preventDefault();
        setWidth(pointerStartWidth + event.clientX - pointerStartX, true);
    }

    /**
     * Commit the matching pointer release after applying its final position.
     * @param {PointerEvent} event Pointer release ending the active gesture.
     * @return {void} Persists the resulting local ratio and releases transient ownership.
     */
    function endPointer(event) {
        if (event.pointerId !== pointerId) return;
        movePointer(event);
        finishDrag(true);
    }

    /**
     * Cancel only this separator's pointer when the browser cancels or loses capture.
     * @param {PointerEvent} event Cancelled pointer or lost-capture event.
     * @return {void} Restores the starting local ratio without persisting a cancelled gesture.
     */
    function cancelPointer(event) {
        if (event.pointerId === pointerId) finishDrag(false);
    }

    /**
     * Reapply the saved percentage against current bounds and retire detached controllers.
     * @return {void} Keeps both panes within their minimums after viewport or parent changes.
     */
    function refresh() {
        if (!workspace.isConnected) {
            finishDrag(false);
            observer?.disconnect();
            window.removeEventListener('resize', refresh);
            return;
        }
        const limits = bounds();
        if (!limits) {
            finishDrag(false);
            workspace.style.removeProperty('--theme-appearance-controls-width');
            handle.setAttribute('aria-disabled', 'true');
            handle.tabIndex = -1;
            return;
        }
        setWidth((preferredRatio === null ? 0.45 : preferredRatio) * limits.available, false);
    }

    /**
     * Restore the default 45 percent settings column without retaining a local override.
     * @return {void} Resets layout only and leaves all unsaved Theme field values intact.
     */
    function reset() {
        finishDrag(false);
        preferredRatio = null;
        persist();
        refresh();
    }

    handle.addEventListener('pointerdown', /**
     * Start a primary pointer resize only while the horizontal separator is available.
     * @param {PointerEvent} event Native pointer activation of the existing separator.
     * @return {void} Captures one pointer and installs temporary movement listeners.
     */ (event) => {
        if (event.button !== 0 || event.isPrimary === false || pointerId !== null || !bounds()) return;
        event.preventDefault();
        pointerId = event.pointerId;
        pointerStartX = event.clientX;
        pointerStartWidth = settings.getBoundingClientRect().width;
        pointerStartRatio = preferredRatio;
        workspace.classList.add('is-resizing');
        handle.focus();
        document.addEventListener('pointermove', movePointer, true);
        document.addEventListener('pointerup', endPointer, true);
        document.addEventListener('pointercancel', cancelPointer, true);
        try { handle.setPointerCapture(event.pointerId); } catch { /* Document listeners also cover capture-unavailable environments. */ }
    });
    handle.addEventListener('lostpointercapture', cancelPointer);
    handle.addEventListener('dblclick', reset);
    handle.addEventListener('keydown', /**
     * Resize with standard separator keys while leaving unrelated keyboard input untouched.
     * @param {KeyboardEvent} event Keyboard activation of the focusable separator.
     * @return {void} Persists bounded arrow/Home/End changes or restores the default on Enter.
     */ (event) => {
        const limits = bounds();
        if (!limits) return;
        if (event.key === 'Enter') { event.preventDefault(); reset(); return; }
        let width = settings.getBoundingClientRect().width;
        const step = event.shiftKey ? 64 : 16;
        if (event.key === 'ArrowLeft') width -= step;
        else if (event.key === 'ArrowRight') width += step;
        else if (event.key === 'Home') width = limits.minimum;
        else if (event.key === 'End') width = limits.maximum;
        else return;
        event.preventDefault();
        setWidth(width, true);
        persist();
    });
    observer = typeof ResizeObserver === 'function' ? new ResizeObserver(refresh) : null;
    observer?.observe(workspace);
    if (workspace.parentElement) observer?.observe(workspace.parentElement);
    window.addEventListener('resize', refresh);
    handle.hidden = false;
    refresh();
}
