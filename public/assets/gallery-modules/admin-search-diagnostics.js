/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-search-diagnostics.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Adds clipboard behavior to the Admin progressive-search diagnostics report.
 *
 * Responsibilities:
 *   - Copy the generated JSON report without mutating its contents
 *   - Preserve a text-selection fallback when the Clipboard API is unavailable
 *   - Remain inert on Admin pages that do not render the diagnostics view
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
 *
 * Last Updated:
 *   2026-09-13
 */

/**
 * Bind the search-diagnostics report copy button when present.
 */
export function setupAdminSearchDiagnostics() {
    const root = document.querySelector('[data-admin-search-diagnostics]');
    if (!root || root.dataset.searchDiagnosticsBound === '1') {
        return;
    }
    root.dataset.searchDiagnosticsBound = '1';

    const button = root.querySelector('[data-admin-search-diagnostics-copy]');
    const source = root.querySelector('[data-admin-search-diagnostics-report]');
    if (!(button instanceof HTMLButtonElement) || !(source instanceof HTMLTextAreaElement)) {
        return;
    }

    button.addEventListener('click', async () => {
        const original = button.textContent || 'Copy report';
        const copiedLabel = root.dataset.copyDoneLabel || 'Copied';
        /** Temporarily mark the copy action as completed, then restore its original label. */
        const markCopied = () => {
            button.textContent = copiedLabel;
            window.setTimeout(() => {
                button.textContent = original;
            }, 1400);
        };

        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(source.value || '');
                markCopied();
                return;
            }
        } catch {
            // Continue to the selection fallback below.
        }

        source.focus();
        source.select();
        try {
            document.execCommand('copy');
            markCopied();
        } catch {
            // Keeping the report selected still gives the operator a manual copy fallback.
        }
    });
}
