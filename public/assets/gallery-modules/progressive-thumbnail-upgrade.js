/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/progressive-thumbnail-upgrade.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Selects, preloads, decodes, and activates one appropriate larger candidate for a progressive public thumbnail.
 *
 * Responsibilities:
 *   - Parse inert width-descriptor candidate lists rendered by PHP
 *   - Convert rendered CSS width and device pixel ratio into a bounded source-width requirement
 *   - Select the smallest adequate candidate without downgrading an already active image
 *   - Preload and decode a browser-supported replacement before changing visible picture/srcset attributes
 *   - Preserve the visible small image when a replacement fails or the lifecycle is aborted
 *
 * Integration points:
 *   - app/services/thumbnail_html.php renders data-progressive-* candidate metadata
 *   - progressive-thumbnail-renderer.js measures relevant cards and calls this module under bounded scheduling
 *   - Native picture/source selection remains in control after a decoded candidate is activated
 *
 * Lifecycle:
 *   A near-viewport scheduler calls upgradeProgressiveThumbnailImage() with a measured target width. This module
 *   derives one candidate per active picture format, preloads the browser-preferred candidate, waits for decode
 *   where supported, then swaps only the planned candidate into live srcset attributes. Later resize passes may
 *   call the same function again; current active widths prevent redundant or downward replacements.
 *
 * Invariants:
 *   - This module never creates the public card or its semantic image element
 *   - Larger URLs remain inert until the scheduler explicitly requests an upgrade
 *   - At most one width candidate per format becomes active during one upgrade pass
 *   - Existing small content stays visible throughout preload/decode and after failures
 *   - Browser-native cache behavior is preserved; image bytes are never fetched through fetch() or Blob URLs
 *
 * Fallback behavior:
 *   WebP is attempted first only when a progressive WebP source exists. If that preload cannot be decoded, the
 *   JPEG img candidate is attempted. A failed replacement leaves all current live srcsets untouched.
 *
 * Accessibility:
 *   The module does not alter alt text, focusability, card links, pointer targets, or ARIA state. Sharpening is a
 *   visual enhancement of an already functional server-rendered image.
 *
 * No-JavaScript behavior:
 *   This module is optional. Without JavaScript, the small src/srcset emitted by PHP remains visible and linked.
 *
 * Performance rationale:
 *   Candidate choice uses actual rendered card width and caps device pixel ratio so very dense displays do not
 *   automatically demand disproportionate derivatives. Decode-before-swap prevents the small image from being
 *   replaced by an empty or partially decoded larger request.
 *
 * Naming:
 *   Progressive is a permanent rendering architecture term. The Admin interface currently marks availability as
 *   Beta, but that maturity label intentionally does not appear in module names, symbols, data markers, or state.
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
 *   2026-08-09
 */

export const PROGRESSIVE_THUMBNAIL_DEVICE_PIXEL_RATIO_CAP = 2;

/**
 * Parse a width-descriptor srcset string into ascending candidates.
 *
 * Progressive thumbnail URLs are generated locally and do not contain unescaped commas, so comma splitting matches
 * the server-generated srcset grammar used throughout this project.
 *
 * @param {string} srcset Inert or active srcset value rendered by PHP.
 * @return {{url: string, width: number}[]} Valid width candidates ordered smallest first.
 */
export function parseProgressiveThumbnailCandidates(srcset) {
    return String(srcset || '')
        .split(',')
        .map((candidate) => candidate.trim())
        .filter(Boolean)
        .map((candidate) => {
            const parts = candidate.split(/\s+/).filter(Boolean);
            const descriptor = parts[1] || '';
            const width = descriptor.endsWith('w') ? Number.parseInt(descriptor.slice(0, -1), 10) : 0;
            return {
                url: parts[0] || '',
                width: Number.isFinite(width) ? width : 0,
            };
        })
        .filter((candidate) => candidate.url !== '' && candidate.width > 0)
        .sort((left, right) => left.width - right.width);
}

/**
 * Calculate the source-pixel width required for the rendered card while bounding high-density display cost.
 *
 * @param {number} renderedWidth Measured CSS pixel width of the card image area.
 * @param {number} devicePixelRatio Current device pixel ratio.
 * @param {number} cap Maximum device pixel ratio considered for thumbnail selection.
 * @return {number} Required source width in pixels, rounded up, or zero for an invalid measurement.
 */
export function progressiveThumbnailRequiredWidth(
    renderedWidth,
    devicePixelRatio = 1,
    cap = PROGRESSIVE_THUMBNAIL_DEVICE_PIXEL_RATIO_CAP
) {
    const cssWidth = Number(renderedWidth);
    if (!Number.isFinite(cssWidth) || cssWidth <= 0) {
        return 0;
    }

    const rawRatio = Number(devicePixelRatio);
    const rawCap = Number(cap);
    const safeRatio = Number.isFinite(rawRatio) && rawRatio > 0 ? rawRatio : 1;
    const safeCap = Number.isFinite(rawCap) && rawCap > 0 ? rawCap : PROGRESSIVE_THUMBNAIL_DEVICE_PIXEL_RATIO_CAP;
    return Math.ceil(cssWidth * Math.min(safeRatio, safeCap));
}

/**
 * Select the smallest available candidate that satisfies a required width without downgrading current content.
 *
 * @param {string} srcset Inert larger-candidate srcset.
 * @param {number} requiredWidth Required source width in pixels.
 * @param {number} currentWidth Width already active for this source or img element.
 * @return {{url: string, width: number}|null} Selected upgrade candidate, or null when no upgrade is needed.
 */
export function selectProgressiveThumbnailCandidate(srcset, requiredWidth, currentWidth = 0) {
    const required = Math.max(0, Math.ceil(Number(requiredWidth) || 0));
    const current = Math.max(0, Math.ceil(Number(currentWidth) || 0));
    if (required <= current) {
        return null;
    }

    const candidates = parseProgressiveThumbnailCandidates(srcset).filter((candidate) => candidate.width > current);
    if (!candidates.length) {
        return null;
    }

    return candidates.find((candidate) => candidate.width >= required) || candidates[candidates.length - 1];
}

/**
 * Return the largest active width descriptor currently exposed on one source or img element.
 *
 * @param {Element} element Picture source or image element.
 * @return {number} Largest active candidate width, or a data-backed fallback when srcset is absent.
 */
function progressiveThumbnailCurrentWidth(element) {
    const activeCandidates = parseProgressiveThumbnailCandidates(element.getAttribute('srcset') || '');
    if (activeCandidates.length) {
        return activeCandidates[activeCandidates.length - 1].width;
    }
    return Math.max(0, Number.parseInt(element.getAttribute('data-progressive-active-width') || '0', 10) || 0);
}

/**
 * Build one format-specific replacement target from inert server-rendered metadata.
 *
 * @param {Element} element Picture source or image element.
 * @param {number} requiredWidth Required source width in pixels.
 * @param {string} measuredSizes Exact measured CSS width expressed as a sizes value.
 * @return {{element: Element, candidate: {url: string, width: number}, sizes: string}|null} Planned replacement.
 */
function progressiveThumbnailTarget(element, requiredWidth, measuredSizes) {
    const inertSrcset = element.getAttribute('data-progressive-srcset') || '';
    const candidate = selectProgressiveThumbnailCandidate(
        inertSrcset,
        requiredWidth,
        progressiveThumbnailCurrentWidth(element)
    );
    if (!candidate) {
        return null;
    }

    return {
        element,
        candidate,
        sizes: measuredSizes || element.getAttribute('data-progressive-sizes') || element.getAttribute('sizes') || '100vw',
    };
}

/**
 * Build a replacement plan for the current picture without mutating live image attributes.
 *
 * @param {HTMLImageElement} image Progressive thumbnail img element.
 * @param {number} requiredWidth Required source width in pixels.
 * @param {string} measuredSizes Exact rendered CSS width expressed as a sizes value.
 * @return {{image: HTMLImageElement, sourceTarget: object|null, imageTarget: object|null}|null} Upgrade plan.
 */
function buildProgressiveThumbnailUpgradePlan(image, requiredWidth, measuredSizes) {
    const picture = image.closest('picture');
    const progressiveSource = picture?.querySelector('source[data-progressive-srcset]') || null;
    const sourceTarget = progressiveSource
        ? progressiveThumbnailTarget(progressiveSource, requiredWidth, measuredSizes)
        : null;
    const imageTarget = image.hasAttribute('data-progressive-srcset')
        ? progressiveThumbnailTarget(image, requiredWidth, measuredSizes)
        : null;

    if (!sourceTarget && !imageTarget) {
        return null;
    }
    return {image, sourceTarget, imageTarget};
}

/**
 * Preload and decode one candidate using the browser's native image loader and cache.
 *
 * Abort does not attempt byte-level cancellation. It prevents a late preload completion from mutating disconnected
 * or reinitialized gallery markup, which is the lifecycle guarantee needed by the renderer.
 *
 * @param {{url: string, width: number}} candidate Candidate selected for activation.
 * @param {AbortSignal|null} signal Renderer lifecycle signal.
 * @return {Promise<boolean>} True when the candidate loaded and is ready to display.
 */
function preloadProgressiveThumbnailCandidate(candidate, signal = null) {
    if (!candidate?.url || signal?.aborted) {
        return Promise.resolve(false);
    }

    return new Promise((resolve) => {
        const preloader = new Image();
        let settled = false;

        /**
         * Finish one preload exactly once and detach lifecycle listeners.
         *
         * @param {boolean} success Whether the candidate is display-ready.
         */
        const finish = (success) => {
            if (settled) {
                return;
            }
            settled = true;
            signal?.removeEventListener('abort', handleAbort);
            preloader.onload = null;
            preloader.onerror = null;
            resolve(success && !signal?.aborted);
        };

        /** Abort callback used only to suppress late DOM mutation from a stale renderer lifecycle. */
        const handleAbort = () => finish(false);

        preloader.decoding = 'async';
        preloader.onload = () => {
            if (typeof preloader.decode !== 'function') {
                finish(true);
                return;
            }
            preloader.decode().then(() => finish(true)).catch(() => finish(false));
        };
        preloader.onerror = () => finish(false);
        signal?.addEventListener('abort', handleAbort, {once: true});
        preloader.src = candidate.url;
    });
}

/**
 * Activate one planned target after its format has been proven loadable.
 *
 * @param {{element: Element, candidate: {url: string, width: number}, sizes: string}|null} target Planned target.
 */
function applyProgressiveThumbnailTarget(target) {
    if (!target) {
        return;
    }
    target.element.setAttribute('sizes', target.sizes);
    target.element.setAttribute('srcset', `${target.candidate.url} ${target.candidate.width}w`);
    target.element.setAttribute('data-progressive-active-width', String(target.candidate.width));
}

/**
 * Upgrade one progressive thumbnail only after a selected replacement has loaded and decoded.
 *
 * The WebP source is preferred when present. If it succeeds, the JPEG fallback can be updated in the same atomic
 * picture mutation because WebP-capable browsers keep the already decoded source candidate. If WebP cannot load,
 * the JPEG candidate is tried and only the img fallback is changed. On any failure the small image remains active.
 *
 * @param {HTMLImageElement} image Progressive thumbnail img element.
 * @param {{requiredWidth: number, measuredSizes: string, signal?: AbortSignal|null}} options Upgrade parameters.
 * @return {Promise<{upgraded: boolean, width: number}>} Upgrade result for scheduling and diagnostics.
 */
export async function upgradeProgressiveThumbnailImage(image, options) {
    const signal = options?.signal || null;
    if (!image?.isConnected || signal?.aborted) {
        return {upgraded: false, width: 0};
    }

    const requiredWidth = Math.max(0, Math.ceil(Number(options?.requiredWidth) || 0));
    if (requiredWidth <= 0) {
        return {upgraded: false, width: 0};
    }

    const plan = buildProgressiveThumbnailUpgradePlan(image, requiredWidth, String(options?.measuredSizes || ''));
    if (!plan) {
        image.closest('picture')?.classList.add('is-progressive-thumbnail-complete');
        return {upgraded: false, width: 0};
    }

    const picture = image.closest('picture');
    picture?.classList.add('is-progressive-thumbnail-sharpening');
    picture?.classList.remove('is-progressive-thumbnail-failed');

    try {
        if (plan.sourceTarget) {
            const sourceReady = await preloadProgressiveThumbnailCandidate(plan.sourceTarget.candidate, signal);
            if (sourceReady && image.isConnected && !signal?.aborted) {
                applyProgressiveThumbnailTarget(plan.sourceTarget);
                applyProgressiveThumbnailTarget(plan.imageTarget);
                picture?.classList.add('is-progressive-thumbnail-complete');
                return {upgraded: true, width: plan.sourceTarget.candidate.width};
            }
        }

        if (plan.imageTarget) {
            const imageReady = await preloadProgressiveThumbnailCandidate(plan.imageTarget.candidate, signal);
            if (imageReady && image.isConnected && !signal?.aborted) {
                applyProgressiveThumbnailTarget(plan.imageTarget);
                picture?.classList.add('is-progressive-thumbnail-complete');
                return {upgraded: true, width: plan.imageTarget.candidate.width};
            }
        }

        if (image.isConnected && !signal?.aborted) {
            picture?.classList.add('is-progressive-thumbnail-failed');
        }
        return {upgraded: false, width: 0};
    } finally {
        picture?.classList.remove('is-progressive-thumbnail-sharpening');
    }
}
