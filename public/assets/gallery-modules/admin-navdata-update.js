/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-navdata-update.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides visible browser feedback while admin navdata imports are starting.
 *
 * Responsibilities:
 *   - Confirm the OurAirports import before submitting
 *   - Disable the import button after submission
 *   - Show an immediate progress state before the browser starts the POST request
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
 *   2026-05-21
 */

/**
 * Attach progress feedback to the flight-map navdata update form.
 *
 * The import is intentionally a normal POST request because shared hosting can
 * be hostile to long AJAX requests and streamed progress. This helper gives the
 * administrator immediate confirmation that the request has started, then lets
 * the existing PHP controller perform the database update and redirect back.
 */
export function setupAdminNavdataUpdateFeedback() {
    document.querySelectorAll('[data-navdata-update-form]').forEach((form) => {
        if (!(form instanceof HTMLFormElement) || form.dataset.navdataFeedbackReady === '1') {
            return;
        }
        form.dataset.navdataFeedbackReady = '1';

        form.addEventListener('submit', (event) => {
            if (form.dataset.navdataSubmitting === '1') {
                return;
            }

            event.preventDefault();
            const confirmMessage = String(form.dataset.navdataConfirm || '').trim();
            if (confirmMessage !== '' && !window.confirm(confirmMessage)) {
                return;
            }

            showNavdataSubmittingState(form);
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    window.setTimeout(() => {
                        HTMLFormElement.prototype.submit.call(form);
                    }, 250);
                });
            });
        });
    });
}

/**
 * Put the navdata form into a visible submitting state.
 *
 * @param {HTMLFormElement} form Form that is about to submit.
 */
function showNavdataSubmittingState(form) {
    form.dataset.navdataSubmitting = '1';
    form.setAttribute('aria-busy', 'true');
    document.body.classList.add('is-navdata-updating');

    const status = form.querySelector('[data-navdata-update-status]');
    if (status instanceof HTMLElement) {
        status.hidden = false;
        status.removeAttribute('hidden');
        status.scrollIntoView({block: 'nearest', inline: 'nearest'});
    }

    const submitButton = form.querySelector('[data-navdata-update-submit]');
    if (submitButton instanceof HTMLButtonElement) {
        const submittingText = String(form.dataset.navdataSubmittingText || '').trim();
        submitButton.disabled = true;
        submitButton.setAttribute('aria-disabled', 'true');
        submitButton.classList.add('is-working');
        if (submittingText !== '') {
            submitButton.textContent = submittingText;
        }
    }
}
