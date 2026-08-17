/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/lightbox_zoom_model_test.mjs
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies public lightbox zoom math without requiring a browser DOM.
 *
 * Responsibilities:
 *   - Cover scale limits and canonical reset state
 *   - Cover translation bounds and pan clamping
 *   - Cover pointer-anchor preservation and percentage formatting
 *
 * Last Updated:
 *   2026-08-16
 */

import {
    LIGHTBOX_ZOOM_MAX_SCALE,
    LIGHTBOX_ZOOM_MIN_SCALE,
    LIGHTBOX_ZOOM_QUALITY_DETAIL_FACTOR,
    LIGHTBOX_ZOOM_QUALITY_MAX_DPR,
    LIGHTBOX_ZOOM_STEP,
    clampLightboxZoomScale,
    createLightboxZoomState,
    lightboxZoomPercentage,
    lightboxZoomRequiredSourceWidth,
    lightboxZoomTranslationBounds,
    normalizeLightboxZoomQualityCandidates,
    normalizeLightboxZoomState,
    panLightboxZoomState,
    selectLightboxZoomQualityCandidate,
    zoomLightboxStateAtAnchor,
    zoomLightboxStateAtPhotoAnchor,
} from '../public/assets/gallery-modules/lightbox-zoom-model.js';

/** Throw when a zoom-model expectation fails. */
function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

const metrics = {
    viewportWidth: 800,
    viewportHeight: 600,
    imageWidth: 800,
    imageHeight: 450,
};

assert(LIGHTBOX_ZOOM_MIN_SCALE === 1, 'minimum scale should remain 100%');
assert(LIGHTBOX_ZOOM_MAX_SCALE === 4, 'maximum scale should remain 400%');
assert(LIGHTBOX_ZOOM_STEP === 0.25, 'button and keyboard step should remain 25%');
assert(clampLightboxZoomScale(-5) === 1, 'scale should clamp below the minimum');
assert(clampLightboxZoomScale(9) === 4, 'scale should clamp above the maximum');
assert(clampLightboxZoomScale(Number.NaN) === 1, 'non-finite scale should reset safely');

const reset = createLightboxZoomState();
assert(reset.scale === 1 && reset.translateX === 0 && reset.translateY === 0, 'reset state should be exactly centered at 100%');
assert(lightboxZoomPercentage(2.25) === '225%', 'status should format a stable percentage');

const bounds = lightboxZoomTranslationBounds(metrics, 2);
assert(bounds.x === 400 && bounds.y === 150, 'translation bounds should reflect scaled content overhang');

const fullscreenWideMetrics = {
    viewportWidth: 1920,
    viewportHeight: 1080,
    imageWidth: 1920,
    imageHeight: 804,
    panViewportWidth: 1920,
    panViewportHeight: 804,
};
const fullscreenWideBounds = lightboxZoomTranslationBounds(fullscreenWideMetrics, 1.25);
assert(fullscreenWideBounds.x === 240 && fullscreenWideBounds.y === 100.5, 'fullscreen pan bounds should use the fitted image rectangle on both axes');
const fullscreenWidePan = panLightboxZoomState({scale: 1.25, translateX: 0, translateY: 0}, 0, 80, fullscreenWideMetrics);
assert(fullscreenWidePan.translateY === 80, 'wide fullscreen images should accept vertical pan immediately after zooming');
const clamped = normalizeLightboxZoomState({scale: 2, translateX: 900, translateY: -900}, metrics);
assert(clamped.translateX === 400 && clamped.translateY === -150, 'normalization should clamp both translation axes');

const panned = panLightboxZoomState({scale: 2, translateX: 0, translateY: 0}, 120, -80, metrics);
assert(panned.translateX === 120 && panned.translateY === -80, 'pan should apply an in-bounds relative delta');
const resetByScale = normalizeLightboxZoomState({scale: 1, translateX: 120, translateY: -80}, metrics);
assert(resetByScale.translateX === 0 && resetByScale.translateY === 0, '100% should always clear stale translation');

const anchored = zoomLightboxStateAtAnchor(reset, 2, {x: 600, y: 300}, metrics);
assert(anchored.scale === 2 && anchored.translateX === -200 && anchored.translateY === 0, 'off-center zoom should preserve the pointer anchor');
const photoAnchored = zoomLightboxStateAtPhotoAnchor(
    {scale: 2, translateX: -120, translateY: 40},
    2.5,
    {x: 610, y: 250},
    metrics,
);
const oldPhotoLeft = metrics.viewportWidth / 2 - (metrics.imageWidth * 2) / 2 - 120;
const oldPhotoTop = metrics.viewportHeight / 2 - (metrics.imageHeight * 2) / 2 + 40;
const oldPhotoFractionX = (610 - oldPhotoLeft) / (metrics.imageWidth * 2);
const oldPhotoFractionY = (250 - oldPhotoTop) / (metrics.imageHeight * 2);
const newPhotoLeft = metrics.viewportWidth / 2 - (metrics.imageWidth * photoAnchored.scale) / 2 + photoAnchored.translateX;
const newPhotoTop = metrics.viewportHeight / 2 - (metrics.imageHeight * photoAnchored.scale) / 2 + photoAnchored.translateY;
const newPhotoFractionX = (610 - newPhotoLeft) / (metrics.imageWidth * photoAnchored.scale);
const newPhotoFractionY = (250 - newPhotoTop) / (metrics.imageHeight * photoAnchored.scale);
assert(Math.abs(oldPhotoFractionX - newPhotoFractionX) < 0.0001, 'successive zoom should preserve the cursor from canonical photo geometry instead of an animated DOM rectangle');
assert(Math.abs(oldPhotoFractionY - newPhotoFractionY) < 0.0001, 'successive zoom should preserve the cursor vertically from canonical photo geometry');

const fullscreenAnchor = {x: 500, y: 260};
const fullscreenStepOne = zoomLightboxStateAtPhotoAnchor(createLightboxZoomState(), 1.25, fullscreenAnchor, fullscreenWideMetrics);
const fullscreenStepTwo = zoomLightboxStateAtPhotoAnchor(fullscreenStepOne, 1.5, fullscreenAnchor, fullscreenWideMetrics);
const fullscreenBaseTop = (fullscreenWideMetrics.viewportHeight - fullscreenWideMetrics.imageHeight) / 2;
const fullscreenInitialFractionY = (fullscreenAnchor.y - fullscreenBaseTop) / fullscreenWideMetrics.imageHeight;
const fullscreenStepTwoTop = fullscreenWideMetrics.viewportHeight / 2 + fullscreenStepTwo.translateY - (fullscreenWideMetrics.imageHeight * fullscreenStepTwo.scale) / 2;
const fullscreenStepTwoFractionY = (fullscreenAnchor.y - fullscreenStepTwoTop) / (fullscreenWideMetrics.imageHeight * fullscreenStepTwo.scale);
assert(Math.abs(fullscreenInitialFractionY - fullscreenStepTwoFractionY) < 0.0001, 'rapid fullscreen zoom should preserve the visible photograph point rather than treating letterbox space as image pixels');

let rapidFullscreenState = createLightboxZoomState();
const rapidFullscreenInitialFractionX = fullscreenAnchor.x / fullscreenWideMetrics.imageWidth;
for (const rapidScale of [1.25, 1.5, 1.75, 2, 2.25, 2.5, 2.75, 3, 3.25, 3.5, 3.75, 4]) {
    rapidFullscreenState = zoomLightboxStateAtPhotoAnchor(rapidFullscreenState, rapidScale, fullscreenAnchor, fullscreenWideMetrics);
    const rapidLeft = fullscreenWideMetrics.viewportWidth / 2 + rapidFullscreenState.translateX - (fullscreenWideMetrics.imageWidth * rapidFullscreenState.scale) / 2;
    const rapidTop = fullscreenWideMetrics.viewportHeight / 2 + rapidFullscreenState.translateY - (fullscreenWideMetrics.imageHeight * rapidFullscreenState.scale) / 2;
    const rapidFractionX = (fullscreenAnchor.x - rapidLeft) / (fullscreenWideMetrics.imageWidth * rapidFullscreenState.scale);
    const rapidFractionY = (fullscreenAnchor.y - rapidTop) / (fullscreenWideMetrics.imageHeight * rapidFullscreenState.scale);
    assert(Math.abs(rapidFullscreenInitialFractionX - rapidFractionX) < 0.0001, `rapid zoom step ${rapidScale} should not drift horizontally toward a corner`);
    assert(Math.abs(fullscreenInitialFractionY - rapidFractionY) < 0.0001, `rapid zoom step ${rapidScale} should not drift vertically toward a corner`);
}
const centered = zoomLightboxStateAtAnchor(reset, 2, {x: 400, y: 300}, metrics);
assert(centered.translateX === 0 && centered.translateY === 0, 'center zoom should remain centered');
const zoomedBack = zoomLightboxStateAtAnchor(anchored, 1, {x: 600, y: 300}, metrics);
assert(zoomedBack.scale === 1 && zoomedBack.translateX === 0 && zoomedBack.translateY === 0, 'zooming back to 100% should use canonical reset state');

const qualityCandidates = [
    {src: '/thumb-1600.webp', width: 1600, height: 1200, kind: 'preview'},
    {src: '/media-original.jpg', width: 8000, height: 6000, kind: 'full'},
];
assert(LIGHTBOX_ZOOM_QUALITY_MAX_DPR === 2, 'quality density should remain bounded at 2x');
assert(LIGHTBOX_ZOOM_QUALITY_DETAIL_FACTOR === 1.5, 'quality demand should retain desktop rendering headroom');
assert(lightboxZoomRequiredSourceWidth(1200, 1, 1) === 1800, 'ordinary 100% viewing should include rendering headroom');
assert(lightboxZoomRequiredSourceWidth(1200, 2, 1) === 3600, 'increased zoom should increase source demand');
assert(lightboxZoomRequiredSourceWidth(1200, 1, 4) === 3600, 'display density should clamp before applying rendering headroom');
assert(selectLightboxZoomQualityCandidate(qualityCandidates, 1200)?.kind === 'preview', 'a sufficient preview should remain selected at 100%');
assert(selectLightboxZoomQualityCandidate(qualityCandidates, 2400, '/thumb-1600.webp')?.kind === 'full', 'zoom beyond preview resolution should promote the full source');
assert(selectLightboxZoomQualityCandidate(qualityCandidates, 1200, '/media-original.jpg')?.kind === 'full', 'a promoted full source should not downgrade while open');
assert(selectLightboxZoomQualityCandidate(qualityCandidates, 20000)?.src === '/media-original.jpg', 'the largest available source should be the bounded fallback');

const normalizedCandidates = normalizeLightboxZoomQualityCandidates([
    null,
    {src: '', width: 1600},
    {src: '/same.webp', width: 800, height: 600},
    {src: '/same.webp', width: 1600, height: 1200},
    {src: '/invalid.webp', width: -1},
]);
assert(normalizedCandidates.length === 1, 'malformed and duplicate quality candidates should collapse safely');
assert(normalizedCandidates[0].width === 1600, 'a duplicate source should retain its largest declared dimensions');
assert(selectLightboxZoomQualityCandidate([], 2000) === null, 'an empty candidate set should fail safely');

console.log('Lightbox zoom model tests passed.');
