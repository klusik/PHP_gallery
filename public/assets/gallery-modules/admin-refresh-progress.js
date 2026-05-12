/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-refresh-progress.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Enhances admin gallery refresh forms with progress feedback.
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

import { i18n } from './admin-core.js?v=20260512-modular-admin-v1';

// Function `setupGalleryRefreshProgress` executes this focused behavior.
export function setupGalleryRefreshProgress() {
    document.querySelectorAll('[data-refresh-galleries-form]').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        form.addEventListener('submit', (event) => {
            if (form.dataset.submitting === '1') {
                return;
            }
            event.preventDefault();
            form.dataset.submitting = '1';
            // Variable `button` stores this steps working value.
            const button = form.querySelector('button[type="submit"], input[type="submit"]');
            if (button) {
                button.disabled = true;
                if ('value' in button && button.tagName === 'INPUT') {
                    button.value = 'Scanning...';
                } else {
                    button.textContent = i18n('admin.operations.scanning', 'Scanning...');
                }
            }
            // progress stores state or configuration for the gallery front-end flow.
            const progress = ensureGalleryRefreshProgress(form);
            progress.hidden = false;
            requestAnimationFrame(() => {
                setTimeout(() => HTMLFormElement.prototype.submit.call(form), 40);
            });
        });
    });
}

// Function `ensureGalleryRefreshProgress` executes this focused behavior.
function ensureGalleryRefreshProgress(form) {
    // progress stores state or configuration for the gallery front-end flow.
    let progress = document.querySelector('[data-gallery-refresh-progress]');
    if (progress) {
        return progress;
    }
    progress = document.createElement('div');
    progress.className = 'thumbnail-progress';
    progress.dataset.galleryRefreshProgress = 'true';
    progress.innerHTML = `<progress class="thumbnail-progress-bar"></progress><p class="muted">${i18n('admin.operations.scan_detail', 'Scanning existing galleries and checking for new gallery folders...')}</p>`;
    // target stores state or configuration for the gallery front-end flow.
    const target = form.closest('.hero') || form;
    target.insertAdjacentElement('afterend', progress);
    return progress;
}
