/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/gallery-presentation.js
 * Module Type: Browser Module
 * Purpose: Resolve bounded gallery defaults and shared presentation snapshots.
 * Responsibilities: Share playback intent without creating a viewer or timer.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/**
 * Keep share links and the three-position HUD on the same bounded vocabulary.
 * Type: ReadonlyArray<string>. Units: ordered preset names, slow/medium/fast.
 * Scope: physical-gallery links, share drafts and live slideshow controls.
 * Consumers: galleryPresentationOptions, share controls and lightbox timing.
 * Rationale: These three positions reuse existing timings without arbitrary URLs.
 */
export const GALLERY_PRESENTATION_SPEEDS = Object.freeze([
    'slow', 'medium', 'fast',
]);

/**
 * Read gallery defaults and valid explicit link overrides before history moves.
 * @param {HTMLElement|null} overlay Current authorized viewer shell.
 * @param {string} href Current URL or a prepared presentation destination.
 * @returns {{music:boolean,autoplay:boolean,fullscreen:boolean,exclusive:boolean,
 *     interval:string,transition:string}} Closed playback
 *     intent; unknown values preserve the gallery defaults.
 */
export function galleryPresentationOptions(
    overlay = document.querySelector('[data-lightbox]'), href = location.href
) {
    const options = {
        autoplay: overlay?.dataset.presentationAutoplay !== '0',
        fullscreen: true,
        exclusive: false,
        music: overlay?.dataset.presentationMusic !== '0',
        interval: overlay?.dataset.presentationInterval || 'medium',
        transition: overlay?.dataset.presentationTransition || 'medium',
    };
    for (const key of ['interval', 'transition']) {
        if (!GALLERY_PRESENTATION_SPEEDS.includes(options[key])) {
            options[key] = 'medium';
        }
    }
    const params = new URL(href, location.href).searchParams;
    if (params.get('presentation') !== '1' && params.get('autoplay') !== '1') {
        return options;
    }
    const music = params.get('presentation_music');
    if (music === '1' || music === '0') options.music = music === '1';
    for (const [key, parameter] of [
        ['autoplay', 'autoplay'], ['fullscreen', 'presentation_fullscreen'],
        ['exclusive', 'presentation_exclusive'],
    ]) {
        const value = params.get(parameter);
        if (value === '1' || value === '0') options[key] = value === '1';
    }
    for (const key of ['interval', 'transition']) {
        const speed = params.get('presentation_' + key);
        if (GALLERY_PRESENTATION_SPEEDS.includes(speed)) options[key] = speed;
    }
    return options;
}
