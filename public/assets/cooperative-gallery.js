/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/cooperative-gallery.js
 * Module Type: Browser Asset
 * Purpose: Present independently authorized cooperative source albums.
 * Responsibilities: Preserve attribution, bounded loading and ordinary link navigation.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
/** Pace pending verification without extending its fixed authorization deadline.
 * Type: number. Units: milliseconds. Scope: public source loading.
 * Consumers: bounded pending loop. Rationale: one second avoids tight requests while remaining responsive.
 */
const COOPERATIVE_POLL_DELAY = 1000;
/** Renew visible source pages before their 120-second derivative tickets expire.
 * Type: number. Units: milliseconds. Scope: each visible cooperative source.
 * Consumers: completed source refresh timers. Rationale: 45 seconds gives renewal time within the one-minute window.
 */
const COOPERATIVE_REFRESH_DELAY = 45000;
const refreshTimers = new WeakMap();
const root = document.querySelector('[data-cooperative-gallery]');
/** Load one source page with a bounded renewal loop.
 * @param {HTMLElement} section Owning source section.
 * @param {HTMLAnchorElement} link Local source or next-page link.
 * @return {Promise<void>} Settles with content or a visible retryable error.
 */
async function loadSource(section, link) {
    if (section.dataset.busy) return;
    section.dataset.busy = '1';
    section.setAttribute('aria-busy', 'true');
    const content = section.querySelector('[data-cooperative-content]');
    const url = new URL(link.href, location.href);
    url.searchParams.set('fragment', '1');
    try {
        // Two installations need at most 2 * (32 members + 1 steps); stop on consent waits or failures.
        for (let step = 0; step < 68; step++) {
            const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', signal: AbortSignal.timeout(20000)});
            const result = await response.json();
            if (!response.ok || result.ok !== true || typeof result.html !== 'string') throw new Error();
            content.innerHTML = result.html;
            if (!result.pending) {
                section.dataset.loadedAt = String(Date.now());
                scheduleSourceRefresh(section, link);
                return;
            }
            await new Promise(/** Pace a pending verification without changing its fixed deadline.
             * Purpose: Bound polling. Type: integer. Units: milliseconds. Scope: this source load.
             * Consumers: pending catalog loop. Rationale: one second avoids tight repeated requests.
             * @param {function(): void} resolve Timer completion.
             * @return {number} Browser timer handle.
             */ resolve => setTimeout(resolve, COOPERATIVE_POLL_DELAY));
        }
        throw new Error();
    } catch {
        const status = document.createElement('p');
        status.setAttribute('role', 'status');
        status.textContent = root.dataset.unavailable;
        content.replaceChildren(status);
    } finally {
        delete section.dataset.busy;
        section.removeAttribute('aria-busy');
    }
}
/** Schedule a bounded refresh of the currently displayed cursor page.
 * @param {HTMLElement} section Source whose media capabilities need renewal.
 * @param {HTMLAnchorElement} link Exact current source/cursor link, even after its DOM replacement.
 * @return {void} Replace the source timer; hidden sources do not cause network requests.
 */
function scheduleSourceRefresh(section, link) {
    clearTimeout(refreshTimers.get(section));
    refreshTimers.set(section, setTimeout(/** Revisit only a visible source in an active document.
     * @return {void} Queue one refresh or postpone while hidden.
     */ () => {
        if (!section.isConnected) return;
        if (!document.hidden && section.dataset.visible === '1') enqueueSource(section, link);
        else scheduleSourceRefresh(section, link);
    }, COOPERATIVE_REFRESH_DELAY));
}
let sourceQueue = Promise.resolve();
/** Serialize source work so a page never races its own group verification.
 * @param {HTMLElement} section Source section.
 * @param {HTMLAnchorElement} link Local catalog link.
 * @return {void} Append one bounded operation without blocking unrelated pages.
 */
function enqueueSource(section, link) {
    sourceQueue = sourceQueue.then(/** Start the next queued source. @return {Promise<void>} Bounded source operation. */ () => loadSource(section, link));
}
if (root) {
    root.addEventListener('click', /** Enhance ordinary source links. @param {MouseEvent} event Delegated click. @return {void} Queue one local request. */ event => {
        const link = event.target.closest('[data-cooperative-load], [data-cooperative-more]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
        event.preventDefault();
        enqueueSource(link.closest('[data-cooperative-source]'), link);
    });
    // Only near-viewport sources request media; each section has a single outstanding operation.
    const observer = new IntersectionObserver(/** Load only sources near the viewport. @param {IntersectionObserverEntry[]} entries Visible source observations. @return {void} Queue each source once. */ entries => {
        for (const entry of entries) {
            entry.target.dataset.visible = entry.isIntersecting ? '1' : '0';
            if (!entry.isIntersecting) continue;
            if (!entry.target.querySelector('[data-cooperative-content]').children.length) {
                enqueueSource(entry.target, entry.target.querySelector('[data-cooperative-load]'));
            }
        }
    }, {rootMargin: '250px'});
    for (const section of root.querySelectorAll('[data-cooperative-source]')) observer.observe(section);
}
