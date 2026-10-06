/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/lightbox-navigation-lifecycle.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Owns the canonical target and generation for one lightbox navigation transaction.
 *
 * Responsibilities:
 *   - Retire superseded navigation signals
 *   - Reject stale navigation callbacks by target and generation
 *   - Expose read-only navigation snapshots for the viewer and diagnostics
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

/**
 * Bound the phases that an active navigation transaction may record.
 * Type: Set<string>
 * Units: Finite transaction phase labels.
 * Scope: One lightbox navigation lifecycle owner.
 * Consumers: setPhase() phase validation.
 * Rationale: Unknown phase names must not overwrite diagnostic transaction state.
 * @var {Set<string>}
 */
const LIGHTBOX_NAVIGATION_PHASES = new Set([
    'intent',
    'metadata',
    'source-selection',
    'presenting',
    'setup-failed',
    'displayed',
    'failed',
]);

/**
 * Immutable token captured by callbacks from one navigation intent.
 * @typedef {{index:number,imageId:string,generation:number,phase:string,signal:AbortSignal}} LightboxNavigationTransaction
 */

/**
 * Read-only state exposed by the current navigation owner.
 * @typedef {{index:number,imageId:string,generation:number,phase:string,detail:string,active:boolean,signal:AbortSignal|null}} LightboxNavigationSnapshot
 */

/**
 * Public lifecycle operations and canonical read-only target accessors.
 * @typedef {Object} LightboxNavigationLifecycle
 * @property {function(number,string=):LightboxNavigationTransaction|null} begin Start and capture one target generation.
 * @property {function(number,number,string):boolean} bindImageId Bind sparse metadata once for the active target.
 * @property {function(number,number):boolean} isCurrent Check target position and generation ownership.
 * @property {function(number,number,string,string=):boolean} setPhase Set a current transaction phase and optional detail.
 * @property {function(number,number,'displayed'|'failed',string=):boolean} settle Record the current terminal presentation outcome.
 * @property {function():LightboxNavigationSnapshot} invalidate Abort the active signal while retaining the last target.
 * @property {function():void} dispose Permanently retire this owner's active transaction.
 * @property {function():LightboxNavigationSnapshot} snapshot Copy current scalar state and signal.
 * @property {number} index Canonical target position.
 * @property {string} imageId Canonical target image identifier.
 * @property {number} generation Current navigation generation.
 * @property {string} phase Current transaction phase.
 * @property {AbortSignal|null} signal Current transaction signal, if active.
 */

/**
 * Create the single mutable navigation owner for one lightbox instance.
 *
 * The generation is a logical stale-result guard. Its signal does not own shared
 * metadata windows, reusable preview preloads, map selection, or quality requests.
 *
 * @return {LightboxNavigationLifecycle} Navigation transaction owner with read-only accessors and lifecycle methods.
 */
export function createLightboxNavigationLifecycle() {
    let index = 0;
    let imageId = '';
    let generation = 0;
    let phase = 'idle';
    let detail = '';
    let controller = null;
    let disposed = false;

    /**
     * Return an immutable view of the current canonical navigation state.
     *
     * @return {LightboxNavigationSnapshot} Copied state and current cancellation signal.
     */
    function snapshot() {
        return Object.freeze({
            index,
            imageId,
            generation,
            phase,
            detail,
            active: !disposed && controller !== null && !controller.signal.aborted,
            signal: controller?.signal || null,
        });
    }

    /**
     * Start a new navigation intent and retire the prior logical transaction.
     *
     * @param {number} targetIndex Zero-based target position in the viewer order.
     * @param {string} targetImageId Stable image identifier when sparse metadata is already loaded.
     * @return {LightboxNavigationTransaction|null} Frozen target token, or null after disposal.
     */
    function begin(targetIndex, targetImageId = '') {
        if (disposed) {
            return null;
        }
        controller?.abort();
        controller = new AbortController();
        index = Number.isInteger(targetIndex) && targetIndex >= 0 ? targetIndex : -1;
        imageId = String(targetImageId || '').trim();
        generation += 1;
        phase = 'intent';
        detail = '';
        return Object.freeze({index, imageId, generation, phase, signal: controller.signal});
    }

    /**
     * Bind the server-provided image identifier after sparse metadata arrives.
     *
     * A transaction may bind an initially unknown identifier once. It cannot be
     * retargeted to another image while keeping the same generation.
     *
     * @param {number} targetIndex Position owned by the transaction.
     * @param {number} targetGeneration Generation returned by begin().
     * @param {string} targetImageId Server-provided stable image identifier.
     * @return {boolean} True when the owner accepted the initial or identical identifier.
     */
    function bindImageId(targetIndex, targetGeneration, targetImageId) {
        if (!isCurrent(targetIndex, targetGeneration)) {
            return false;
        }
        const normalizedImageId = String(targetImageId || '').trim();
        if (imageId !== '') {
            return imageId === normalizedImageId;
        }
        if (normalizedImageId === '') {
            return true;
        }
        imageId = normalizedImageId;
        return true;
    }

    /**
     * Check whether a captured index and generation still own the active intent.
     *
     * @param {number} targetIndex Captured target position.
     * @param {number} targetGeneration Captured generation token.
     * @return {boolean} True while the exact logical navigation remains current.
     */
    function isCurrent(targetIndex, targetGeneration) {
        return !disposed
            && controller !== null
            && !controller.signal.aborted
            && index === targetIndex
            && generation === targetGeneration;
    }

    /**
     * Advance the diagnostic phase for the current navigation owner.
     *
     * @param {number} targetIndex Captured target position.
     * @param {number} targetGeneration Captured generation token.
     * @param {string} nextPhase Current transaction phase.
     * @param {string} reason Optional bounded failure or wait detail.
     * @return {boolean} True when the phase belongs to the current transaction.
     */
    function setPhase(targetIndex, targetGeneration, nextPhase, reason = '') {
        if (
            !isCurrent(targetIndex, targetGeneration)
            || phase === 'displayed'
            || phase === 'failed'
            || !LIGHTBOX_NAVIGATION_PHASES.has(nextPhase)
        ) {
            return false;
        }
        phase = nextPhase;
        detail = String(reason || '').replace(/\s+/g, ' ').slice(0, 64);
        return true;
    }

    /**
     * Settle the current transaction while keeping it current for same-photo quality work.
     *
     * @param {number} targetIndex Captured target position.
     * @param {number} targetGeneration Captured generation token.
     * @param {'displayed'|'failed'} outcome Terminal presentation outcome.
     * @param {string} reason Optional bounded failure detail.
     * @return {boolean} True when the current transaction accepted the outcome.
     */
    function settle(targetIndex, targetGeneration, outcome, reason = '') {
        if (
            (outcome !== 'displayed' && outcome !== 'failed')
            || phase === 'displayed'
            || phase === 'failed'
        ) {
            return false;
        }
        return setPhase(targetIndex, targetGeneration, outcome, reason);
    }

    /**
     * Invalidate current callbacks while retaining the last selected target for close/reopen behavior.
     *
     * @return {LightboxNavigationSnapshot} Snapshot after the current signal has been aborted.
     */
    function invalidate() {
        if (disposed) {
            return snapshot();
        }
        controller?.abort();
        controller = null;
        generation += 1;
        phase = 'closed';
        detail = '';
        return snapshot();
    }

    /**
     * Permanently dispose this viewer's navigation owner.
     *
     * @return {void} Aborts the active logical transaction and refuses future intents.
     */
    function dispose() {
        if (disposed) {
            return;
        }
        invalidate();
        disposed = true;
        phase = 'disposed';
    }

    return Object.freeze({
        begin,
        bindImageId,
        isCurrent,
        setPhase,
        settle,
        invalidate,
        dispose,
        snapshot,
        /**
         * Return the canonical target position without allocating a snapshot.
         *
         * @return {number} Current zero-based target position.
         */
        get index() { return index; },
        /**
         * Return the canonical target image identifier without allocating a snapshot.
         *
         * @return {string} Stable server-provided image ID, when known.
         */
        get imageId() { return imageId; },
        /**
         * Return the current navigation generation without allocating a snapshot.
         *
         * @return {number} Monotonic transaction generation.
         */
        get generation() { return generation; },
        /**
         * Return the current transaction phase without allocating a snapshot.
         *
         * @return {string} Current navigation phase label.
         */
        get phase() { return phase; },
        /**
         * Return the current intent's cancellation signal, when active.
         *
         * @return {AbortSignal|null} Signal aborted by supersession, invalidation, or disposal.
         */
        get signal() { return controller?.signal || null; },
    });
}
