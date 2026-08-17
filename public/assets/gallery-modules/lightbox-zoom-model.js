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
 *   - Keep model behavior testable without a browser DOM
 *
 * Last Updated:
 *   2026-08-16
 */

export const LIGHTBOX_ZOOM_MIN_SCALE = 1;
export const LIGHTBOX_ZOOM_MAX_SCALE = 4;
export const LIGHTBOX_ZOOM_STEP = 0.25;
export const LIGHTBOX_ZOOM_QUALITY_MAX_DPR = 2;
export const LIGHTBOX_ZOOM_QUALITY_DETAIL_FACTOR = 1.5;
export const LIGHTBOX_ZOOM_QUALITY_UPGRADE_THRESHOLD = 1;

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
