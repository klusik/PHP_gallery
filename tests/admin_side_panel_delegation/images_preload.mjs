/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_side_panel_delegation/images_preload.mjs
 * Module Type: Regression Test
 * Purpose: Exercise bounded production thumbnail admission, failures and cancellation.
 * Responsibilities: Fail the existing central delegation owner on preload regressions.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
const source = await readFile(new URL('../../public/assets/gallery-modules/admin-gallery-images-preload.js', import.meta.url), 'utf8');
const {preloadEditorThumbnails} = await import('data:text/javascript;base64,' + Buffer.from(source.replace(/^import \{captureAdminPanelOwner\}[^\n]+/m, 'const captureAdminPanelOwner = () => ({isCurrent: () => true});')).toString('base64'));
let active = 0;
let peak = 0;
const requested = [];

/** Represent thumbnail events while counting the production queue's admitted resources. */
class QueueThumbnail extends EventTarget {
    /**
     * Create one deterministic resource outcome.
     * @param {string} outcome Success, failure, decode failure or timeout.
     * @returns {void} Initialize an unrequested thumbnail.
     */
    constructor(outcome) {
        super();
        this.outcome = outcome;
        this.dataset = {adminPreloadSrc: '/thumb?size=300&id=' + requested.length};
        this.complete = false;
        this.naturalWidth = 0;
        this.pending = false;
    }
    /**
     * Observe each actual source assignment as a resource admission.
     * @param {string} value Authorized thumbnail URL.
     * @returns {void} Start its bounded asynchronous load.
     */
    set src(value) {
        requested.push(value);
        this.pending = true;
        active += 1;
        peak = Math.max(peak, active);
        if (this.outcome === 'timeout') return;
        this.timer = setTimeout(() => {
            if (!this.pending) return;
            this.pending = false;
            active -= 1;
            this.complete = true;
            this.naturalWidth = this.outcome === 'failure' ? 0 : 1;
            this.dispatchEvent(new Event(this.outcome === 'failure' ? 'error' : 'load'));
        }, 3);
    }
    /**
     * Settle a successful or deliberately undecodable thumbnail.
     * @returns {Promise<void>} Decode completion or explicit resource failure.
     */
    async decode() {
        if (this.outcome === 'decode-failure') throw new Error('Unreadable fixture thumbnail.');
    }
    /**
     * Cancel the exact owned resource without affecting other workers.
     * @param {string} name Attribute removed by production recovery.
     * @returns {void} Stop pending source work.
     */
    removeAttribute(name) {
        if (name !== 'src' || !this.pending) return;
        clearTimeout(this.timer);
        this.pending = false;
        active -= 1;
    }
}
for (const count of [0, 1, 50, 500, 1000, 3001]) {
    peak = 0;
    const settled = [];
    const images = Array.from({length: count}, () => new QueueThumbnail('success'));
    await preloadEditorThumbnails(images, new AbortController().signal, loaded => settled.push(loaded));
    assert.equal(settled.length, count);
    assert.ok(settled.every(Boolean));
    assert.equal(active, 0);
    assert.ok(peak <= 6);
    if (count > 1) assert.ok(peak > 1, 'Preload is genuinely parallel at its production admission boundary.');
}
const failures = [];
await preloadEditorThumbnails(['success', 'failure', 'decode-failure', 'timeout'].map(outcome => new QueueThumbnail(outcome)),
    new AbortController().signal, loaded => failures.push(loaded), 4, 15);
assert.equal(failures.filter(Boolean).length, 1);
assert.equal(failures.filter(loaded => !loaded).length, 3);
assert.equal(active, 0, 'Timeout removes the admitted resource before another task owns it.');
const cancelled = new AbortController();
const blocked = Array.from({length: 50}, () => new QueueThumbnail('timeout'));
const running = preloadEditorThumbnails(blocked, cancelled.signal, () => { throw new Error('Cancelled resources cannot report successful progress.'); });
cancelled.abort();
await assert.rejects(running);
assert.equal(active, 0);
assert.equal(blocked.filter(image => image.dataset.adminThumbnailFailed).length, 0);
await assert.rejects(preloadEditorThumbnails([], new AbortController().signal, () => {}, 0));
assert.ok(requested.every(url => url.includes('size=300')), 'Only supplied table thumbnail URLs are admitted.');
const tabs = await readFile(new URL('../../public/assets/gallery-modules/admin-tabs.js', import.meta.url), 'utf8');
assert.ok(tabs.includes("getAttribute('aria-disabled') === 'true'") && tabs.includes('enabledTabs'));
assert.ok(tabs.includes("imageWidget.dataset.requested = '1'"), 'Direct Images intent survives deferred readiness.');
assert.ok(source.includes('owner.isCurrent()') && source.includes('widget.isConnected'));
assert.ok(source.includes("payload.gallery_id !== Number(widget.dataset.galleryId)") && source.includes('seen.has(id)'));
assert.ok(source.includes('imagesPanel.replaceChildren') && !source.includes('form.innerHTML'), 'Photo batches cannot overwrite settings drafts.');
console.log('Images preload queue: PASS; 0/1/50/500/1000/3001 resources, bounded parallelism, decode/error/timeout and cancellation.');
