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
 * Author:
 *   Rudolf Klusal
 *
 * License: MIT License (see LICENSE file in repository)
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
    LIGHTBOX_CONTENT_POLICY,
    detectLightboxContentBounds,
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

/**
 * Build exact bounded raster vectors without browser or image-file dependencies.
 * @param {{left:number,top:number,right:number,bottom:number}} edges Content rectangle in the 128-square sample.
 * @param {number} noise Maximum deterministic near-black noise level.
 * @returns {{width:number,height:number,data:Uint8ClampedArray}} Opaque RGBA raster with textured bright content.
 */
function contentSample(edges, noise = 0) {
    // Structural corpus size: each coordinate maps directly to one sample pixel.
    const size = 128;
    const data = new Uint8ClampedArray(size * size * 4);
    for (let y = 0; y < size; y += 1) {
        for (let x = 0; x < size; x += 1) {
            const offset = (y * size + x) * 4;
            const content = x >= edges.left && x < edges.right
                && y >= edges.top && y < edges.bottom;
            const level = content ? 80 + (x + y) % 120
                : (x + y) % (noise + 1);
            data.set([level, level, level, 255], offset);
        }
    }
    return {width: size, height: size, data};
}

// Exact boundaries cover letterbox, pillarbox, asymmetric and four-sided mattes.
for (const edges of [
    {left: 0, top: 16, right: 128, bottom: 112},
    {left: 24, top: 0, right: 104, bottom: 128},
    {left: 9, top: 14, right: 100, bottom: 110},
    {left: 0, top: 12, right: 128, bottom: 128},
]) {
    for (const noise of [0, 8]) {
        const bounds = detectLightboxContentBounds(contentSample(edges, noise));
        assert(bounds && bounds.left === edges.left / 128
            && bounds.top === edges.top / 128
            && bounds.right === edges.right / 128
            && bounds.bottom === edges.bottom / 128,
        'confident opaque matte bounds must match the exact corpus rectangle');
    }
}
const fullFrame = {left: 0, top: 0, right: 128, bottom: 128};
assert(detectLightboxContentBounds(contentSample(fullFrame)) === null,
    'ordinary textured image must remain unchanged');
const black = contentSample({left: 0, top: 0, right: 0, bottom: 0});
assert(detectLightboxContentBounds(black) === null,
    'black/fade frame must remain unchanged');
const dark = contentSample({left: 0, top: 16, right: 128, bottom: 112});
for (let offset = 0; offset < dark.data.length; offset += 4) {
    if (dark.data[offset] > 0) dark.data.set([25, 25, 25], offset);
}
assert(detectLightboxContentBounds(dark) === null,
    'ambiguous dark content must not establish a crop transition');
// The reported game screenshots have equal 12.5% pillars, with one or both
// adjacent content edges below level 40. Rotate the pattern to cover both axes.
for (const vertical of [false, true]) {
    for (const brightOpposite of [false, true]) {
        const edges = vertical
            ? {left: 16, top: 0, right: 112, bottom: 128}
            : {left: 0, top: 16, right: 128, bottom: 112};
        const game = contentSample(edges, 8);
        for (let y = edges.top; y < edges.bottom; y += 1) {
            for (let x = edges.left; x < edges.right; x += 1) {
                // Levels 22--35 supply variation without strict bright evidence.
                const across = vertical ? y : x;
                const inward = vertical ? x : y;
                const level = brightOpposite && inward >= 64
                    ? 100 : 22 + across % 14;
                game.data.set([level, level, level], (y * 128 + x) * 4);
            }
        }
        const bounds = detectLightboxContentBounds(game);
        assert(bounds?.left === edges.left / 128
            && bounds?.top === edges.top / 128
            && bounds?.right === edges.right / 128
            && bounds?.bottom === edges.bottom / 128,
        'matching mattes must fit one or two dim textured content edges');
    }
}
// Reported 0003 has fewer than 15% lit pixels at one edge; 0004 meets a
// nearly flat dim wall. Equal opposite mattes plus a qualified textured edge
// can corroborate a sharp mean step. Cover both axes and both orientations.
for (const vertical of [false, true]) {
    for (const reverse of [false, true]) {
        for (const pattern of ['sparse', 'flat', 'gradual']) {
            const edges = vertical
                ? {left: 16, top: 0, right: 112, bottom: 128}
                : {left: 0, top: 16, right: 128, bottom: 112};
            const game = contentSample(edges, 8);
            for (let y = edges.top; y < edges.bottom; y += 1) {
                for (let x = edges.left; x < edges.right; x += 1) {
                    const across = vertical ? y : x;
                    const inward = reverse ? 127 - (vertical ? x : y)
                        : vertical ? x : y;
                    // A gradual edge cannot corroborate a dim opposite one.
                    // Give that negative case independently bright evidence
                    // so its untouched edge can legitimately fit on its own.
                    let level = pattern === 'gradual' ? 100 : 22 + across % 14;
                    if (inward < 64) {
                        if (pattern === 'flat') level = 25;
                        else if (pattern === 'gradual') {
                            level = Math.min(35, 8 + (inward - 16) * 3)
                                + across % 2;
                        } else {
                            // 10% light pixels are below the existing 15% test.
                            level = across % 10 === 0 ? 28 : 17 + across % 3;
                        }
                    }
                    game.data.set([level, level, level], (y * 128 + x) * 4);
                }
            }
            const bounds = detectLightboxContentBounds(game);
            const first = vertical ? 'left' : 'top';
            const last = vertical ? 'right' : 'bottom';
            const tested = reverse ? last : first;
            const opposite = reverse ? first : last;
            assert(bounds?.[opposite] === (reverse ? 16 / 128 : 112 / 128),
                'the independently qualifying opposite matte must still fit');
            assert(bounds?.[tested] === (pattern === 'gradual'
                ? (reverse ? 1 : 0) : (reverse ? 112 / 128 : 16 / 128)),
            'sharp sparse/flat edges must fit; gradual dark boundaries must remain');
            assert(bounds?.[vertical ? 'top' : 'left'] === 0
                && bounds?.[vertical ? 'bottom' : 'right'] === 1,
            'boundary-step evidence must not trim the other axis');
        }
    }
}
// Samples 0051--0053 have black padding next to content that can itself pass
// the ordinary near-black scan. The symmetric hint must find measured cuts,
// including a mostly flat edge, without inventing an absent/asymmetric matte.
for (const vertical of [false, true]) {
    for (const reverse of [false, true]) {
        for (const noise of [0, 2]) {
            for (const pattern of ['starfield', 'near-black', 'unequal',
                'unpaired', 'flat', 'isolated', 'gradual']) {
                const start = pattern === 'unpaired' && reverse ? 0 : 16;
                const end = pattern === 'unpaired' && !reverse ? 128
                    : pattern === 'unequal' ? 104 : 112;
                const edges = vertical
                    ? {left: start, top: 0, right: end, bottom: 128}
                    : {left: 0, top: start, right: 128, bottom: end};
                const scene = contentSample(edges, noise);
                for (let y = edges.top; y < edges.bottom; y += 1) {
                    for (let x = edges.left; x < edges.right; x += 1) {
                        const along = vertical ? x : y;
                        const across = vertical ? y : x;
                        const dimSide = reverse ? along >= 64 : along < 64;
                        const distance = reverse ? end - 1 - along : along - start;
                        let level = pattern === 'near-black'
                            ? 14 + (across % 8 === 0 ? 40 : 0)
                            : 22 + across % 14;
                        if (dimSide) {
                            level = pattern === 'near-black'
                                ? 5 + across % 10 : 4 + across % 13;
                            if (pattern === 'gradual') {
                                level = Math.min(18, distance * 2) + across % 2;
                            }
                        }
                        if (pattern === 'flat') level = 25;
                        if (pattern === 'isolated') {
                            // One bright point per line cannot supply 15% texture.
                            level = across === 64 ? 88 : 8;
                        }
                        scene.data.set([level, level, level], (y * 128 + x) * 4);
                    }
                }
                if (noise === 2) {
                    // CPU Canvas sampling can leave one level-9 JPEG speck on
                    // the exterior boundary; it must not shift the real cut.
                    const exterior = reverse ? start - 1 : end;
                    if (exterior >= 0 && exterior < 128) {
                        const offset = (vertical ? 64 * 128 + exterior
                            : exterior * 128 + 64) * 4;
                        scene.data.set([9, 9, 9], offset);
                    }
                }
                const bounds = detectLightboxContentBounds(scene);
                if (pattern === 'starfield' || pattern === 'near-black') {
                    assert(bounds?.left === edges.left / 128
                        && bounds?.top === edges.top / 128
                        && bounds?.right === edges.right / 128
                        && bounds?.bottom === edges.bottom / 128,
                    'symmetric black cuts must retain adjacent near-black content');
                } else {
                    assert(bounds === null,
                        `symmetry must not invent a cut for ${pattern} content`);
                }
            }
        }
    }
}
// Mean steps alone cannot corroborate each other, even with isolated noise.
const flatPair = contentSample({left: 16, top: 0, right: 112, bottom: 128});
for (let y = 0; y < 128; y += 1) {
    for (let x = 16; x < 112; x += 1) {
        const level = y === 64 && x === 16 ? 100 : 25;
        flatPair.data.set([level, level, level], (y * 128 + x) * 4);
    }
}
assert(detectLightboxContentBounds(flatPair) === null,
    'two flat dim edges with isolated noise must not establish a crop');
const mismatchedDim = contentSample(
    {left: 16, top: 0, right: 104, bottom: 128});
for (let y = 0; y < 128; y += 1) {
    for (let x = 16; x < 104; x += 1) {
        const level = 22 + y % 14;
        mismatchedDim.data.set([level, level, level], (y * 128 + x) * 4);
    }
}
assert(detectLightboxContentBounds(mismatchedDim) === null,
    'unequal dim edges must not borrow the matching-matte exception');
const subtitles = contentSample({left: 0, top: 16, right: 128, bottom: 100});
// A short subtitle interrupts the bottom matte before the real content begins.
for (let x = 48; x < 80; x += 1) {
    subtitles.data.set([255, 255, 255], (110 * 128 + x) * 4);
}
const subtitleBounds = detectLightboxContentBounds(subtitles);
assert(subtitleBounds?.top === 16 / 128 && subtitleBounds?.bottom === 1
    && subtitleBounds?.left === 0 && subtitleBounds?.right === 1,
    'a subtitle must retain its edge while the independently proven top matte fits');
const concert = contentSample({left: 0, top: 16, right: 128, bottom: 112});
// Match the reported concert screenshot: a bright upper edge, only 18.75%
// bright content at the lower edge, and dim side gradients without a hard cut.
for (let y = 16; y < 112; y += 1) {
    for (let x = 0; x < 128; x += 1) {
        let level = 5 + (x + y) % 6;
        if ((x >= 17 && x < 23) || (x >= 115 && x < 120)) level = 25;
        if ((y < 64 && x >= 40 && x < 106)
            || (y >= 64 && x >= 90 && x < 114)) level = 100;
        concert.data.set([level, level, level], (y * 128 + x) * 4);
    }
}
const concertBounds = detectLightboxContentBounds(concert);
assert(concertBounds?.top === 16 / 128 && concertBounds?.bottom === 112 / 128
    && concertBounds?.left === 0 && concertBounds?.right === 1,
    'paired mattes must fit dark content without trimming natural dark side gradients');
// Canvas downsampling may retain one dim mixed row outside the real content.
// Evidence may look past it, but the actual crop must retain that boundary row.
for (let x = 0; x < 128; x += 1) {
    concert.data.set([25, 25, 25], (112 * 128 + x) * 4);
}
const blendedConcertBounds = detectLightboxContentBounds(concert);
assert(blendedConcertBounds?.top === 16 / 128
    && blendedConcertBounds?.bottom === 113 / 128
    && blendedConcertBounds?.left === 0 && blendedConcertBounds?.right === 1,
    'a blended boundary may delay transition evidence without cropping real pixels');
const unpairedConcert = {...concert, data: concert.data.slice()};
// Without an opposite matte, the same dim lower edge cannot use paired evidence.
for (let y = 0; y < 16; y += 1) {
    for (let x = 0; x < 128; x += 1) {
        unpairedConcert.data.set([100, 100, 100], (y * 128 + x) * 4);
    }
}
assert(detectLightboxContentBounds(unpairedConcert) === null,
    'dim content must not qualify without a matching opposite matte');
const transparent = contentSample({left: 16, top: 0, right: 112, bottom: 128});
for (let offset = 3; offset < transparent.data.length; offset += 4) {
    transparent.data[offset] = 0;
}
assert(detectLightboxContentBounds(transparent) === null,
    'transparent pixels must never count as black mattes');
assert(detectLightboxContentBounds(contentSample(
    {left: 42, top: 0, right: 86, bottom: 128})) === null,
    'excessive trim must preserve the original frame');
assert(detectLightboxContentBounds({width: 300, height: 300,
    data: new Uint8ClampedArray(0)}) === null,
    'unbounded/malformed input must be rejected');
assert(LIGHTBOX_CONTENT_POLICY.sampleEdge === 256
    && LIGHTBOX_CONTENT_POLICY.cacheEntries === 64,
    'sampling and cache design budgets must remain explicit');

console.log('Lightbox zoom model tests passed. Content-fit corpus passed.');
