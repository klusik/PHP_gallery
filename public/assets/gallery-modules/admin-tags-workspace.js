/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-tags-workspace.js
 * Module Type: Browser Module
 * Purpose: Own immediate tag-catalog filters and contextual editor help.
 * Responsibilities: Submit native catalog reads and bound help on pages/panels.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/**
 * Keep help clear of its invoker and every viewport edge.
 * Type: number. Units: CSS pixels. Scope: tag-editor help geometry.
 * Consumers: positionAdminTagHelp(). Value: 8.
 * Rationale: match the existing compact Features/Maintenance help spacing.
 */
const TAG_HELP_GAP_PX = 8;

let tagsWorkspaceBound = false;
const tagSelectionResetReads = new WeakSet();

/**
 * Place one native popover beside its invoker without moving form controls.
 * @param {HTMLElement} panel Open tag-help popover with escaped explanation.
 * @param {HTMLButtonElement} trigger Owning question-mark button.
 * @return {void} Fit the help below or above its invoker inside the client box.
 */
function positionAdminTagHelp(panel, trigger) {
    const gap = TAG_HELP_GAP_PX;
    const width = document.documentElement.clientWidth;
    const height = document.documentElement.clientHeight;
    const rect = trigger.getBoundingClientRect();
    panel.style.position = 'fixed';
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
 * Enhance native filter/sort reads and contextual tag help exactly once.
 * @return {void} Preserve native Escape/outside dismissal and invoker focus;
 *     submit filters on change, retain native Apply without JavaScript, and
 *     cover tag forms inserted by the existing side panel through delegation.
 */
export function setupAdminTagsWorkspace() {
    if (tagsWorkspaceBound) return;
    tagsWorkspaceBound = true;
    document.querySelectorAll('.admin-tags-sort-form').forEach(form => {
        form.classList.add('is-enhanced');
        form.addEventListener('formdata', event => {
            if (tagSelectionResetReads.has(form)) event.formData.delete('id');
        });
    });
    document.addEventListener('change', event => {
        const control = event.target;
        if (!(control instanceof HTMLSelectElement)
            || !control.matches('[name="media_type"], [name="sort"]')) return;
        const form = control.closest('form.admin-tags-sort-form');
        if (!(form instanceof HTMLFormElement)) return;
        if (control.name === 'media_type') {
            // A new usage scope selects its own first tag; sorting retains the
            // selected tag. Change this read's entries without altering the
            // native fields retained by browser Back/Forward restoration.
            tagSelectionResetReads.add(form);
        }
        try {
            form.requestSubmit();
        } finally {
            tagSelectionResetReads.delete(form);
        }
    });
    const reposition = () => {
        for (const panel of document.querySelectorAll(
            '.admin-tags-help-panel:popover-open',
        )) {
            const trigger = document.querySelector(
                `[popovertarget="${CSS.escape(panel.id)}"]`,
            );
            if (trigger instanceof HTMLButtonElement) {
                positionAdminTagHelp(panel, trigger);
            }
        }
    };
    document.addEventListener('toggle', event => {
        if (event.target instanceof HTMLElement
            && event.target.matches('.admin-tags-help-panel:popover-open')) {
            reposition();
        }
    }, true);
    window.addEventListener('resize', reposition);
    window.addEventListener('scroll', reposition, true);
}
