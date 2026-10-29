/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/lightbox-zoom-model.js
 * Module Type: Browser-independent Model
 *
 * Purpose:
 *   Owns bounded scale and translation math for the public lightbox image.
 *
 * Responsibilities:
 *   - Define the supported zoom range and keyboard/button step
 *   - Clamp panning to the scaled image bounds
 *   - Preserve a pointer or pinch midpoint while scale changes
 *   - Select the smallest safe source that satisfies the active zoom density
 *   - Detect bounded black mattes without changing stored image pixels
 *   - Keep model behavior testable without a browser DOM
 *
 * Author:
 *   Rudolf Klusal
 *
 * License: MIT License (see LICENSE file in repository)
 *
 * Last Updated:
 *   2026-10-29
 */

export const LIGHTBOX_ZOOM_MIN_SCALE = 1;
export const LIGHTBOX_ZOOM_MAX_SCALE = 4;
export const LIGHTBOX_ZOOM_STEP = 0.25;
export const LIGHTBOX_ZOOM_QUALITY_MAX_DPR = 2;
export const LIGHTBOX_ZOOM_QUALITY_DETAIL_FACTOR = 1.5;
export const LIGHTBOX_ZOOM_QUALITY_UPGRADE_THRESHOLD = 1;

/**
 * Bound conservative matte detection independently of source resolution.
 * Type: {sampleEdge:number,cacheEntries:number,blackCeiling:number,
 * blackMean:number,blackDeviation:number,minBand:number,maxTrim:number,
 * minContent:number,transitionLight:number,transitionShare:number,
 * transitionDepth:number,transitionRamp:number,pairedTransitionShare:number,
 * pairedTransitionLight:number,pairedTransitionContrast:number,
 * pairedTransitionDepth:number,pairedTransitionMeanRise:number,
 * pairedBandTolerance:number,pairedBlackMean:number,
 * pairedBoundaryMeanRise:number,pairedBoundaryLight:number,
 * aspectTolerance:number,version:string}.
 * Units: pixels, entries, byte levels and dimensionless fractions as below.
 * Scope: one active lightbox; no background gallery analysis.
 * Consumers: detectLightboxContentBounds and the lightbox canvas/cache owner.
 * Rationale: 256-square sampling caps RGBA storage at 256 KiB; 64 cached
 * rectangles bound session memory. These are conservative design budgets,
 * not measured low-end-device performance guarantees. A 12-level mean rise
 * over four adjacent lines admits dim scene cuts while rejecting gradual
 * boundaries; a qualified opposite transition is mandatory corroboration.
 * A separate symmetric candidate caps the band mean at 4, retaining the
 * ordinary channel/noise limits, and needs a six-level rise on both sides.
 * Sustained level-12 spatial variation on at least one side distinguishes
 * near-black scenes from uniform fades.
 */
export const LIGHTBOX_CONTENT_POLICY = Object.freeze({
    sampleEdge: 256,
    cacheEntries: 64,
    blackCeiling: 20, // Every channel must tolerate only faint JPEG noise.
    blackMean: 12, // Reject uniformly dark gray photographic backgrounds.
    blackDeviation: 6, // Reject texture even below the near-black ceiling.
    minBand: 3, // Require three contiguous sampled lines, not a dark edge.
    maxTrim: 0.30, // Never remove more than 30% from any individual edge.
    minContent: 0.40, // Retain at least 40% on each axis.
    transitionLight: 40, // Content must clearly exceed the black ceiling.
    transitionShare: 0.35, // A subtitle/logo alone is insufficient evidence.
    transitionDepth: 2, // Require the transition to persist into content.
    transitionRamp: 2, // Retain up to two blended boundary lines during evidence checks.
    pairedTransitionShare: 0.15, // Dark content needs a matching opposite matte.
    pairedTransitionLight: 24, // Accept dim game scenes above near-black noise.
    pairedTransitionContrast: 12, // Require visible variation, not a flat fade.
    pairedTransitionDepth: 4, // Paired dark edges need four persistent content lines.
    pairedTransitionMeanRise: 12, // Sharp mean rise above the adjacent matte line.
    pairedBandTolerance: 2, // Opposite mattes may differ by two sample pixels.
    pairedBlackMean: 4, // Separate padding from dim content despite JPEG specks.
    pairedBoundaryMeanRise: 6, // Both symmetric cuts must have an immediate step.
    pairedBoundaryLight: 12, // Dark texture can corroborate the opposite cut.
    aspectTolerance: 0.02, // Allow 2% derivative rounding, not a different frame.
    version: 'content-v5', // Invalidate cached decisions when policy changes.
});

/**
 * Locate convincing contiguous black mattes in a bounded opaque RGBA sample.
 *
 * Horizontal bands are found first, then columns within their content span.
 * An uncertain edge retains its extent without rejecting other proven edges.
 * Matched opposite mattes can establish darker sustained transitions on both
 * edges. One qualifying opposite content edge can corroborate a sharp mean
 * brightness step beside a flat or sparsely lit edge. Two flat dim edges remain
 * uncertain. An independent edge still needs the ordinary bright-content test.
 * An incomplete axis also probes stricter black bands: symmetry requires two
 * measured cuts with immediate mean steps and sustained texture on one side.
 * This keeps near-black content that the ordinary band scan may absorb.
 * Real dark backgrounds can resemble a matte, so the viewer offers an
 * original-frame override.
 *
 * @param {{width:number,height:number,data:Uint8ClampedArray}} sample Small RGBA raster, at most 256 pixels per axis.
 * @returns {{left:number,top:number,right:number,bottom:number}|null} Normalized content edges, or null when unchanged/uncertain.
 */
export function detectLightboxContentBounds(sample) {
    const {width, height, data} = sample || {};
    const policy = LIGHTBOX_CONTENT_POLICY;
    // Four channels per pixel are the structural RGBA wire shape.
    if (!Number.isInteger(width) || !Number.isInteger(height)
        || width < policy.minBand || height < policy.minBand
        || width > policy.sampleEdge || height > policy.sampleEdge
        || !(data instanceof Uint8ClampedArray)
        || data.length !== width * height * 4) return null;

    const scanBand = (vertical, reverse, start, end,
        meanLimit = policy.blackMean) => {
        const extent = vertical ? width : height;
        const limit = Math.floor(extent * policy.maxTrim);
        const count = end - start;
        let band = 0;
        for (; band <= limit; band += 1) {
            const line = reverse ? extent - 1 - band : band;
            let sum = 0;
            let squares = 0;
            let black = true;
            for (let position = start; position < end; position += 1) {
                const offset = (vertical
                    ? position * width + line : line * width + position) * 4;
                const level = Math.max(data[offset], data[offset + 1],
                    data[offset + 2]);
                if (data[offset + 3] !== 255
                    || level > policy.blackCeiling) black = false;
                sum += level;
                squares += level * level;
            }
            const mean = sum / count;
            if (!black || mean > meanLimit
                || squares / count - mean * mean
                    > policy.blackDeviation * policy.blackDeviation) break;
        }
        if (band < policy.minBand) return 0;
        if (band > limit) return -1;
        return band;
    };
    const hasTransition = (vertical, reverse, band, start, end, share,
        depthCount, light = policy.transitionLight, contrast = 0,
        rampLimit = policy.transitionRamp) => {
        if (band <= 0) return false;
        const extent = vertical ? width : height;
        const count = end - start;
        // Downsampling can leave a dim boundary line before real content. Look
        // only a bounded distance inward, keeping those lines inside the crop.
        for (let ramp = 0; ramp <= rampLimit; ramp += 1) {
            let sustained = true;
            for (let depth = 0; depth < depthCount; depth += 1) {
                const distance = band + ramp + depth;
                const line = reverse ? extent - 1 - distance : distance;
                let bright = 0;
                let minimum = 255;
                let maximum = 0;
                for (let position = start; position < end; position += 1) {
                    const offset = (vertical
                        ? position * width + line : line * width + position) * 4;
                    if (data[offset + 3] !== 255) continue;
                    const level = Math.max(data[offset], data[offset + 1],
                        data[offset + 2]);
                    if (level >= light) bright += 1;
                    minimum = Math.min(minimum, level);
                    maximum = Math.max(maximum, level);
                }
                if (bright / count < share || maximum - minimum < contrast) {
                    sustained = false;
                    break;
                }
            }
            if (sustained) return true;
        }
        return false;
    };
    // A dim wall or sparsely lit edge can lack the transition's pixel share
    // or range. Require a sharp step from the last matte line on every inward
    // line, without looking past a gradual boundary. Callers corroborate it
    // through an ordinary opposite transition or the stricter symmetric pair.
    const hasPairedBoundaryStep = (vertical, reverse, band, start, end,
        meanRise = policy.pairedTransitionMeanRise) => {
        if (band <= 0) return false;
        const extent = vertical ? width : height;
        const exterior = reverse ? extent - band : band - 1;
        const count = end - start;
        let exteriorSum = 0;
        for (let position = start; position < end; position += 1) {
            const offset = (vertical
                ? position * width + exterior : exterior * width + position) * 4;
            exteriorSum += Math.max(data[offset], data[offset + 1],
                data[offset + 2]);
        }
        for (let depth = 0; depth < policy.pairedTransitionDepth; depth += 1) {
            const line = reverse ? extent - 1 - band - depth : band + depth;
            let sum = 0;
            for (let position = start; position < end; position += 1) {
                const offset = (vertical
                    ? position * width + line : line * width + position) * 4;
                if (data[offset + 3] !== 255) return false;
                sum += Math.max(data[offset], data[offset + 1],
                    data[offset + 2]);
            }
            if ((sum - exteriorSum) / count < meanRise) return false;
        }
        return true;
    };
    const resolveAxis = (vertical, start, end) => {
        const first = scanBand(vertical, false, start, end);
        const last = scanBand(vertical, true, start, end);
        const firstStrong = hasTransition(vertical, false, first, start, end,
            policy.transitionShare, policy.transitionDepth);
        const lastStrong = hasTransition(vertical, true, last, start, end,
            policy.transitionShare, policy.transitionDepth);
        const paired = first > 0 && last > 0
            && Math.abs(first - last) <= policy.pairedBandTolerance;
        const firstSupported = firstStrong || (paired && hasTransition(
            vertical, false, first, start, end, policy.pairedTransitionShare,
            policy.pairedTransitionDepth, policy.pairedTransitionLight,
            policy.pairedTransitionContrast));
        const lastSupported = lastStrong || (paired && hasTransition(
            vertical, true, last, start, end, policy.pairedTransitionShare,
            policy.pairedTransitionDepth, policy.pairedTransitionLight,
            policy.pairedTransitionContrast));
        // At least one side must qualify without borrowing a mean step. This
        // retains uniform dim/fade frames while allowing a real scene to meet
        // one matte through a flat wall or a sparsely illuminated boundary.
        const pairedSupported = paired && (firstSupported || lastSupported)
            && (firstSupported
                || hasPairedBoundaryStep(vertical, false, first, start, end))
            && (lastSupported
                || hasPairedBoundaryStep(vertical, true, last, start, end));
        const resolved = {first: firstStrong || pairedSupported ? first : 0,
            last: lastStrong || pairedSupported ? last : 0};
        if (resolved.first && resolved.last) return resolved;

        // Near-black content can satisfy the ordinary matte ceiling for many
        // lines. Symmetry is a hint to probe a stricter, independently measured
        // pair, never permission to mirror a crop onto an unverified edge.
        const hintedFirst = scanBand(vertical, false, start, end,
            policy.pairedBlackMean);
        const hintedLast = scanBand(vertical, true, start, end,
            policy.pairedBlackMean);
        if (hintedFirst <= 0 || hintedLast <= 0
            || Math.abs(hintedFirst - hintedLast) > policy.pairedBandTolerance
            || !hasPairedBoundaryStep(vertical, false, hintedFirst, start, end,
                policy.pairedBoundaryMeanRise)
            || !hasPairedBoundaryStep(vertical, true, hintedLast, start, end,
                policy.pairedBoundaryMeanRise)) return resolved;

        // Texture must start at the same measured cut (zero ramp), persist for
        // four lines, and occupy the normal 15% share. A few bright points
        // alone cannot supply the required texture share.
        const hasTexture = reverse => hasTransition(vertical, reverse,
            reverse ? hintedLast : hintedFirst, start, end,
            policy.pairedTransitionShare, policy.pairedTransitionDepth,
            policy.pairedBoundaryLight, policy.pairedTransitionContrast, 0);
        return hasTexture(false) || hasTexture(true)
            ? {first: hintedFirst, last: hintedLast} : resolved;
    };
    const {first: top, last: bottom} = resolveAxis(false, 0, width);
    const {first: left, last: right} = resolveAxis(true, top, height - bottom);
    if (!(left || top || right || bottom)
        || (width - left - right) / width < policy.minContent
        || (height - top - bottom) / height < policy.minContent) return null;
    return {left: left / width, top: top / height,
        right: 1 - right / width, bottom: 1 - bottom / height};
}

/**
 * Clamp a numeric value to an inclusive range.
 *
 * @param {number} value Candidate value.
 * @param {number} minimum Inclusive lower bound.
 * @param {number} maximum Inclusive upper bound.
 * @return {number} Finite bounded value.
 */
function clampLightboxZoomValue(value, minimum, maximum) {
    const numericValue = Number.isFinite(value) ? value : minimum;
    return Math.min(maximum, Math.max(minimum, numericValue));
}

/**
 * Round transform values enough to avoid accumulating floating-point noise.
 *
 * @param {number} value Candidate transform value.
 * @return {number} Stable transform value.
 */
function roundLightboxZoomValue(value) {
    return Math.round(value * 1000) / 1000;
}

/**
 * Return the canonical unzoomed lightbox state.
 *
 * @return {{scale: number, translateX: number, translateY: number}} Fresh default state.
 */
export function createLightboxZoomState() {
    return {
        scale: LIGHTBOX_ZOOM_MIN_SCALE,
        translateX: 0,
        translateY: 0,
    };
}

/**
 * Clamp a requested lightbox scale to the supported range.
 *
 * @param {number} scale Requested scale.
 * @return {number} Supported scale from 1 through 4.
 */
export function clampLightboxZoomScale(scale) {
    return roundLightboxZoomValue(clampLightboxZoomValue(scale, LIGHTBOX_ZOOM_MIN_SCALE, LIGHTBOX_ZOOM_MAX_SCALE));
}

/**
 * Calculate the maximum translation that still keeps scaled image content over the viewport.
 *
 * @param {{viewportWidth?: number, viewportHeight?: number, imageWidth?: number, imageHeight?: number, panViewportWidth?: number, panViewportHeight?: number}} metrics Measured base dimensions.
 * @param {number} scale Active scale.
 * @return {{x: number, y: number}} Symmetric horizontal and vertical bounds.
 */
export function lightboxZoomTranslationBounds(metrics, scale) {
    const viewportWidth = Math.max(0, Number(metrics?.viewportWidth) || 0);
    const viewportHeight = Math.max(0, Number(metrics?.viewportHeight) || 0);
    const imageWidth = Math.max(0, Number(metrics?.imageWidth) || 0);
    const imageHeight = Math.max(0, Number(metrics?.imageHeight) || 0);
    const panViewportWidth = Math.max(0, Number(metrics?.panViewportWidth) || viewportWidth);
    const panViewportHeight = Math.max(0, Number(metrics?.panViewportHeight) || viewportHeight);
    const boundedScale = clampLightboxZoomScale(scale);
    return {
        x: Math.max(0, (imageWidth * boundedScale - panViewportWidth) / 2),
        y: Math.max(0, (imageHeight * boundedScale - panViewportHeight) / 2),
    };
}

/**
 * Normalize a complete state against the current viewport and image dimensions.
 *
 * @param {{scale?: number, translateX?: number, translateY?: number}} state Candidate state.
 * @param {{viewportWidth?: number, viewportHeight?: number, imageWidth?: number, imageHeight?: number, panViewportWidth?: number, panViewportHeight?: number}} metrics Measured base dimensions.
 * @return {{scale: number, translateX: number, translateY: number}} Bounded state.
 */
export function normalizeLightboxZoomState(state, metrics) {
    const scale = clampLightboxZoomScale(Number(state?.scale));
    if (scale <= LIGHTBOX_ZOOM_MIN_SCALE) {
        return createLightboxZoomState();
    }
    const bounds = lightboxZoomTranslationBounds(metrics, scale);
    return {
        scale,
        translateX: roundLightboxZoomValue(clampLightboxZoomValue(Number(state?.translateX), -bounds.x, bounds.x)),
        translateY: roundLightboxZoomValue(clampLightboxZoomValue(Number(state?.translateY), -bounds.y, bounds.y)),
    };
}

/**
 * Change scale while keeping the image point beneath a viewport anchor stationary.
 *
 * @param {{scale?: number, translateX?: number, translateY?: number}} state Current state.
 * @param {number} requestedScale Requested target scale.
 * @param {{x?: number, y?: number}} anchor Viewport-relative pointer or pinch midpoint.
 * @param {{viewportWidth?: number, viewportHeight?: number, imageWidth?: number, imageHeight?: number, panViewportWidth?: number, panViewportHeight?: number}} metrics Measured base dimensions.
 * @return {{scale: number, translateX: number, translateY: number}} Anchored bounded state.
 */
export function zoomLightboxStateAtAnchor(state, requestedScale, anchor, metrics) {
    const current = normalizeLightboxZoomState(state, metrics);
    const scale = clampLightboxZoomScale(requestedScale);
    if (scale <= LIGHTBOX_ZOOM_MIN_SCALE) {
        return createLightboxZoomState();
    }
    const viewportWidth = Math.max(0, Number(metrics?.viewportWidth) || 0);
    const viewportHeight = Math.max(0, Number(metrics?.viewportHeight) || 0);
    const anchorX = Number.isFinite(Number(anchor?.x)) ? Number(anchor.x) : viewportWidth / 2;
    const anchorY = Number.isFinite(Number(anchor?.y)) ? Number(anchor.y) : viewportHeight / 2;
    const centeredX = anchorX - viewportWidth / 2;
    const centeredY = anchorY - viewportHeight / 2;
    const imagePointX = (centeredX - current.translateX) / current.scale;
    const imagePointY = (centeredY - current.translateY) / current.scale;
    return normalizeLightboxZoomState({
        scale,
        translateX: centeredX - imagePointX * scale,
        translateY: centeredY - imagePointY * scale,
    }, metrics);
}

/**
 * Change scale while preserving the fitted photograph point under an anchor.
 *
 * Unlike DOM-rectangle anchoring, this function derives the currently rendered
 * photograph rectangle from canonical zoom state and the 100% fitted dimensions.
 * That keeps rapid consecutive zoom inputs deterministic even while CSS transitions
 * are visually between two committed scale values. Anchors outside the photograph
 * are clamped to its nearest edge before the next state is calculated.
 *
 * @param {{scale?: number, translateX?: number, translateY?: number}} state Current state.
 * @param {number} requestedScale Requested target scale.
 * @param {{x?: number, y?: number}} anchor Stage-relative pointer or pinch midpoint.
 * @param {{viewportWidth?: number, viewportHeight?: number, imageWidth?: number, imageHeight?: number, panViewportWidth?: number, panViewportHeight?: number}} metrics Measured 100% geometry.
 * @return {{scale: number, translateX: number, translateY: number}} Anchored bounded state.
 */
export function zoomLightboxStateAtPhotoAnchor(state, requestedScale, anchor, metrics) {
    const current = normalizeLightboxZoomState(state, metrics);
    const scale = clampLightboxZoomScale(requestedScale);
    if (scale <= LIGHTBOX_ZOOM_MIN_SCALE) {
        return createLightboxZoomState();
    }

    const viewportWidth = Math.max(0, Number(metrics?.viewportWidth) || 0);
    const viewportHeight = Math.max(0, Number(metrics?.viewportHeight) || 0);
    const baseImageWidth = Math.max(0, Number(metrics?.imageWidth) || 0);
    const baseImageHeight = Math.max(0, Number(metrics?.imageHeight) || 0);
    if (!viewportWidth || !viewportHeight || !baseImageWidth || !baseImageHeight) {
        return zoomLightboxStateAtAnchor(current, scale, anchor, metrics);
    }

    const currentWidth = baseImageWidth * current.scale;
    const currentHeight = baseImageHeight * current.scale;
    const currentLeft = viewportWidth / 2 + current.translateX - currentWidth / 2;
    const currentTop = viewportHeight / 2 + current.translateY - currentHeight / 2;
    const rawAnchorX = Number.isFinite(Number(anchor?.x)) ? Number(anchor.x) : viewportWidth / 2;
    const rawAnchorY = Number.isFinite(Number(anchor?.y)) ? Number(anchor.y) : viewportHeight / 2;
    const anchorX = clampLightboxZoomValue(rawAnchorX, currentLeft, currentLeft + currentWidth);
    const anchorY = clampLightboxZoomValue(rawAnchorY, currentTop, currentTop + currentHeight);
    const imageFractionX = clampLightboxZoomValue((anchorX - currentLeft) / currentWidth, 0, 1);
    const imageFractionY = clampLightboxZoomValue((anchorY - currentTop) / currentHeight, 0, 1);
    const nextWidth = baseImageWidth * scale;
    const nextHeight = baseImageHeight * scale;
    const desiredCenterX = anchorX + (0.5 - imageFractionX) * nextWidth;
    const desiredCenterY = anchorY + (0.5 - imageFractionY) * nextHeight;

    return normalizeLightboxZoomState({
        scale,
        translateX: desiredCenterX - viewportWidth / 2,
        translateY: desiredCenterY - viewportHeight / 2,
    }, metrics);
}

/**
 * Change scale around an anchor measured against the image rectangle the browser
 * is actually rendering. This avoids drift when the zoom surface has already
 * grown beyond its 100% fitted box or when fullscreen letterboxing is present.
 *
 * @param {{scale?: number, translateX?: number, translateY?: number}} state Current state.
 * @param {number} requestedScale Requested target scale.
 * @param {{x?: number, y?: number}} anchor Stage-relative pointer or pinch midpoint.
 * @param {{viewportWidth?: number, viewportHeight?: number, imageWidth?: number, imageHeight?: number, panViewportWidth?: number, panViewportHeight?: number}} metrics Measured 100% geometry.
 * @param {{left?: number, top?: number, width?: number, height?: number}} renderedImageRect Current image rectangle relative to the stage.
 * @return {{scale: number, translateX: number, translateY: number}} Anchored bounded state.
 */
export function zoomLightboxStateAtRenderedAnchor(state, requestedScale, anchor, metrics, renderedImageRect) {
    const current = normalizeLightboxZoomState(state, metrics);
    const scale = clampLightboxZoomScale(requestedScale);
    if (scale <= LIGHTBOX_ZOOM_MIN_SCALE) {
        return createLightboxZoomState();
    }

    const viewportWidth = Math.max(0, Number(metrics?.viewportWidth) || 0);
    const viewportHeight = Math.max(0, Number(metrics?.viewportHeight) || 0);
    const baseImageWidth = Math.max(0, Number(metrics?.imageWidth) || 0);
    const baseImageHeight = Math.max(0, Number(metrics?.imageHeight) || 0);
    const anchorX = Number.isFinite(Number(anchor?.x)) ? Number(anchor.x) : viewportWidth / 2;
    const anchorY = Number.isFinite(Number(anchor?.y)) ? Number(anchor.y) : viewportHeight / 2;
    const renderedWidth = Math.max(0, Number(renderedImageRect?.width) || 0);
    const renderedHeight = Math.max(0, Number(renderedImageRect?.height) || 0);
    const renderedLeft = Number(renderedImageRect?.left);
    const renderedTop = Number(renderedImageRect?.top);

    if (
        !viewportWidth
        || !viewportHeight
        || !baseImageWidth
        || !baseImageHeight
        || !renderedWidth
        || !renderedHeight
        || !Number.isFinite(renderedLeft)
        || !Number.isFinite(renderedTop)
    ) {
        return zoomLightboxStateAtAnchor(current, scale, {x: anchorX, y: anchorY}, metrics);
    }

    const imageFractionX = clampLightboxZoomValue((anchorX - renderedLeft) / renderedWidth, 0, 1);
    const imageFractionY = clampLightboxZoomValue((anchorY - renderedTop) / renderedHeight, 0, 1);
    const nextWidth = baseImageWidth * scale;
    const nextHeight = baseImageHeight * scale;
    const desiredCenterX = anchorX + (0.5 - imageFractionX) * nextWidth;
    const desiredCenterY = anchorY + (0.5 - imageFractionY) * nextHeight;

    return normalizeLightboxZoomState({
        scale,
        translateX: desiredCenterX - viewportWidth / 2,
        translateY: desiredCenterY - viewportHeight / 2,
    }, metrics);
}

/**
 * Apply a relative pan delta to a zoomed lightbox state.
 *
 * @param {{scale?: number, translateX?: number, translateY?: number}} state Current state.
 * @param {number} deltaX Horizontal movement in pixels.
 * @param {number} deltaY Vertical movement in pixels.
 * @param {{viewportWidth?: number, viewportHeight?: number, imageWidth?: number, imageHeight?: number, panViewportWidth?: number, panViewportHeight?: number}} metrics Measured base dimensions.
 * @return {{scale: number, translateX: number, translateY: number}} Panned bounded state.
 */
export function panLightboxZoomState(state, deltaX, deltaY, metrics) {
    const current = normalizeLightboxZoomState(state, metrics);
    return normalizeLightboxZoomState({
        scale: current.scale,
        translateX: current.translateX + (Number(deltaX) || 0),
        translateY: current.translateY + (Number(deltaY) || 0),
    }, metrics);
}

/**
 * Format the current scale as a whole-number percentage for visible controls.
 *
 * @param {number} scale Active scale.
 * @return {string} Percentage such as 100% or 225%.
 */
export function lightboxZoomPercentage(scale) {
    return `${Math.round(clampLightboxZoomScale(scale) * 100)}%`;
}

/**
 * Normalize and sort server-authorized lightbox quality candidates.
 *
 * Duplicate URLs collapse to their largest declared dimensions. Invalid entries
 * are ignored so stale or malformed markup cannot influence source selection.
 *
 * @param {Array<{src?: string, width?: number, height?: number, kind?: string}>} candidates Raw server candidates.
 * @return {Array<{src: string, width: number, height: number, kind: string}>} Safe candidates sorted by pixel width.
 */
export function normalizeLightboxZoomQualityCandidates(candidates) {
    const normalizedBySource = new Map();
    (Array.isArray(candidates) ? candidates : []).forEach((candidate) => {
        const src = typeof candidate?.src === 'string' ? candidate.src.trim() : '';
        const width = Math.round(Number(candidate?.width));
        const height = Math.round(Number(candidate?.height));
        if (!src || !Number.isFinite(width) || width <= 0 || width > 100000) {
            return;
        }
        const normalized = {
            src,
            width,
            height: Number.isFinite(height) && height > 0 && height <= 100000 ? height : 0,
            kind: typeof candidate?.kind === 'string' ? candidate.kind : '',
        };
        const existing = normalizedBySource.get(src);
        if (!existing || normalized.width > existing.width) {
            normalizedBySource.set(src, normalized);
        }
    });
    return Array.from(normalizedBySource.values()).sort((left, right) => left.width - right.width);
}

/**
 * Calculate how many source pixels the current rendered and zoomed image needs.
 *
 * @param {number} renderedWidth Current image width in CSS pixels before transform.
 * @param {number} scale Current bounded lightbox scale.
 * @param {number} devicePixelRatio Browser display density.
 * @return {number} Conservative required source width in pixels.
 */
export function lightboxZoomRequiredSourceWidth(renderedWidth, scale, devicePixelRatio = 1) {
    const cssWidth = Math.max(0, Number(renderedWidth) || 0);
    const density = clampLightboxZoomValue(Number(devicePixelRatio), 1, LIGHTBOX_ZOOM_QUALITY_MAX_DPR);
    return Math.ceil(cssWidth * clampLightboxZoomScale(scale) * density * LIGHTBOX_ZOOM_QUALITY_DETAIL_FACTOR);
}

/**
 * Select the smallest quality candidate that satisfies the current pixel demand.
 *
 * The current source is retained when it remains within the upgrade threshold,
 * and a promoted source is never downgraded while the photograph stays open.
 *
 * @param {Array<{src?: string, width?: number, height?: number, kind?: string}>} candidates Raw server candidates.
 * @param {number} requiredWidth Required source width in pixels.
 * @param {string} currentSource Currently displayed or promoted source URL.
 * @return {{src: string, width: number, height: number, kind: string}|null} Desired candidate.
 */
export function selectLightboxZoomQualityCandidate(candidates, requiredWidth, currentSource = '') {
    const normalized = normalizeLightboxZoomQualityCandidates(candidates);
    if (normalized.length === 0) {
        return null;
    }
    const demand = Math.max(1, Math.ceil(Number(requiredWidth) || 1));
    const current = normalized.find((candidate) => candidate.src === currentSource) || null;
    if (current && current.width >= demand * LIGHTBOX_ZOOM_QUALITY_UPGRADE_THRESHOLD) {
        return current;
    }
    const desired = normalized.find((candidate) => candidate.width >= demand) || normalized[normalized.length - 1];
    if (current && current.width >= desired.width) {
        return current;
    }
    return desired;
}
