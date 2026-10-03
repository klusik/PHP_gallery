/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-navdata-update.js
 * Module Type: Browser Module
 * Purpose: Refresh OurAirports navigation data without blocking page navigation.
 * Responsibilities: Own AJAX submission, periodic visible-panel checks and canonical completion.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {ADMIN_NAVDATA_SUBMIT_FEEDBACK_MS} from './admin-interaction-policy.js?v=20260920-admin-interaction-policy-v1';
import {completeAdminMutation} from './admin-mutation-completion.js';

/** Run an owned navdata refresh. @param {HTMLFormElement} form Current import form. @param {boolean} periodic Whether this is the weekly age check. @return {Promise<void>} Refreshes the owned card without changing the URL. */
async function refreshNavdata(form, periodic = false) {
    if (form.dataset.navdataSubmitting === '1') return;
    form.dataset.navdataSubmitting = '1';
    form.setAttribute('aria-busy', 'true');
    const button = form.querySelector('[data-navdata-update-submit]');
    const status = form.querySelector('[data-navdata-update-status]');
    const text = form.querySelector('[data-navdata-status-text]');
    if (text) text.textContent = form.dataset.navdataSubmittingText || text.textContent;
    status?.classList.remove('has-error');
    const spinner = status?.querySelector('.admin-navdata-update-spinner');
    if (spinner) spinner.hidden = false;
    if (button) button.disabled = true;
    if (status) status.hidden = false;
    const card = form.closest('[data-navdata-card]');
    const body = new FormData(form);
    body.set('ajax', '1');
    if (periodic) body.set('navdata_check_due', '1');
    try {
        const response = await fetch(form.action, {method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, body});
        const payload = await response.json();
        if (!response.ok || !payload?.ok) throw new Error(payload.message || 'Navigation data refresh failed.');
        if (payload.state === 'updated') await completeAdminMutation(payload);
        if (!card?.isConnected) return;
        if (typeof payload.html === 'string') {
            const template = document.createElement('template');
            template.innerHTML = payload.html;
            const replacement = template.content.querySelector('[data-navdata-card]');
            if (replacement) {
                const message = document.createElement('p');
                message.className = 'muted';
                message.setAttribute('role', 'status');
                message.textContent = String(payload.message || '');
                replacement.append(message);
                card.replaceWith(replacement);
            }
        }
    } catch (error) {
        if (text) text.textContent = error.message;
        status?.classList.add('has-error');
        const spinner = status?.querySelector('.admin-navdata-update-spinner');
        if (spinner) spinner.hidden = true;
    } finally {
        form.dataset.navdataSubmitting = '0';
        form.removeAttribute('aria-busy');
        if (button) button.disabled = false;
    }
}

/** Install dynamic AJAX submission and weekly checks for visible navdata cards. @return {void} Keeps normal POST available when JavaScript is absent. */
export function setupAdminNavdataUpdateFeedback() {
    if (!window.fetch || document.documentElement.dataset.navdataAjaxReady === '1') return;
    document.documentElement.dataset.navdataAjaxReady = '1';
    document.addEventListener('submit', /** Intercept only the owned import form. @param {SubmitEvent} event Form intent. @return {void} Starts an in-place import. */ event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-navdata-update-form]')) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        refreshNavdata(form);
    }, true);
    const observed = new WeakSet();
    const visibility = new IntersectionObserver(/** Check only a visible due card. @param {IntersectionObserverEntry[]} entries Visibility observations. @return {void} Starts one deferred age check. */ entries => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            visibility.unobserve(entry.target);
            window.setTimeout(/** Allow initial page paint before the background import. @return {void} Requests a weekly check if still connected. */ () => {
                if (entry.target.isConnected) refreshNavdata(entry.target, true);
            }, ADMIN_NAVDATA_SUBMIT_FEEDBACK_MS);
        }
    });
    /** Observe newly rendered due forms once. @return {void} Registers visible-panel freshness checks. */
    function discover() {
        for (const form of document.querySelectorAll('[data-navdata-update-form][data-navdata-auto-check="1"]')) {
            if (!observed.has(form)) { observed.add(form); visibility.observe(form); }
        }
    }
    discover();
    new MutationObserver(/** Discover forms inserted by dashboard/drawer refreshes. @return {void} Admits only unobserved due forms. */ () => discover()).observe(document.body, {childList: true, subtree: true});
}
