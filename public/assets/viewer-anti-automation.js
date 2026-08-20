/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/viewer-anti-automation.js
 * Module Type: Public JavaScript Asset
 *
 * Purpose:
 *   Solves the optional first-party viewer anti-automation SHA-256 challenge using native browser APIs.
 *
 * Responsibilities:
 *   - Read only server-issued local challenge metadata from the current form
 *   - Use Web Crypto SHA-256 without third-party libraries, remote imports, or fingerprinting
 *   - Keep work bounded by the server-supplied hard counter ceiling
 *   - Fall back to the server-enforced first-party no-JavaScript path when Web Crypto is unavailable
 */

(function () {
    'use strict';

    /**
     * Return whether a SHA-256 byte array satisfies the requested leading-zero-bit target.
     *
     * @param {Uint8Array} bytes SHA-256 digest bytes.
     * @param {number} requiredBits Required leading zero bits.
     * @returns {boolean} True when the target is satisfied.
     */
    function hasLeadingZeroBits(bytes, requiredBits) {
        var fullBytes = Math.floor(requiredBits / 8);
        var remainingBits = requiredBits % 8;
        var index;
        for (index = 0; index < fullBytes; index += 1) {
            if (bytes[index] !== 0) {
                return false;
            }
        }
        if (remainingBits === 0) {
            return true;
        }
        var mask = (0xFF << (8 - remainingBits)) & 0xFF;
        return (bytes[fullBytes] & mask) === 0;
    }

    /**
     * Yield briefly so a bounded proof loop does not monopolize the browser main thread.
     *
     * @returns {Promise<void>} Completion after a zero-delay browser task.
     */
    function yieldToBrowser() {
        return new Promise(function (resolve) {
            window.setTimeout(resolve, 0);
        });
    }

    /**
     * Reveal the server-side first-party fallback and update localized status text.
     *
     * @param {HTMLFormElement} form Challenge form.
     * @param {HTMLElement|null} status Status element.
     */
    function showFallback(form, status) {
        var fallback = form.querySelector('[data-viewer-aa-fallback]');
        if (fallback) {
            fallback.hidden = false;
        }
        if (status) {
            status.textContent = form.dataset.viewerAaFailed || status.textContent;
        }
    }

    /**
     * Solve one bounded SHA-256 proof-of-work challenge and enable normal continuation.
     *
     * @param {HTMLFormElement} form Challenge form.
     * @returns {Promise<void>} Completion after a solution or fallback decision.
     */
    async function solveChallenge(form) {
        var status = form.querySelector('[data-viewer-aa-status]');
        var progress = form.querySelector('[data-viewer-aa-progress]');
        var counterField = form.querySelector('[data-viewer-aa-counter]');
        var continueButton = form.querySelector('[data-viewer-aa-continue]');
        var action = form.dataset.viewerAaAction || '';
        var challenge = form.dataset.viewerAaChallenge || '';
        var difficulty = Number.parseInt(form.dataset.viewerAaDifficulty || '', 10);
        var maxCounter = Number.parseInt(form.dataset.viewerAaMaxCounter || '', 10);

        if (!window.crypto || !window.crypto.subtle || typeof window.TextEncoder !== 'function'
            || !counterField || !continueButton || !action || !challenge
            || !Number.isInteger(difficulty) || difficulty < 1
            || !Number.isInteger(maxCounter) || maxCounter < 0) {
            showFallback(form, status);
            return;
        }

        var encoder = new TextEncoder();
        var counter;
        for (counter = 0; counter <= maxCounter; counter += 1) {
            var input = 'viewer-aa-pow-v1\n' + action + '\n' + challenge + '\n' + counter;
            var digest = await window.crypto.subtle.digest('SHA-256', encoder.encode(input));
            if (hasLeadingZeroBits(new Uint8Array(digest), difficulty)) {
                counterField.value = String(counter);
                continueButton.disabled = false;
                if (progress) {
                    progress.value = 100;
                }
                if (status) {
                    status.textContent = form.dataset.viewerAaReady || status.textContent;
                }
                return;
            }
            if (counter % 128 === 127) {
                if (progress) {
                    progress.value = Math.min(99, Math.floor((counter / Math.max(1, maxCounter)) * 100));
                }
                await yieldToBrowser();
            }
        }
        showFallback(form, status);
    }

    /**
     * Initialize the single Phase 4.3 challenge form when present on the page.
     */
    function initializeViewerAntiAutomation() {
        var form = document.querySelector('form[data-viewer-anti-automation]');
        if (!form) {
            return;
        }
        var solvePromise = solveChallenge(form);
        solvePromise.catch(function () {
            showFallback(form, form.querySelector('[data-viewer-aa-status]'));
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeViewerAntiAutomation);
    } else {
        initializeViewerAntiAutomation();
    }
}());
