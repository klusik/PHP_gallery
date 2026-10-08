/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-upload-thumbnail.js
 * Module Type: Browser Module
 * Purpose: Generate disposable upload miniatures without retaining decoded originals.
 * Responsibilities:
 *   - Inspect bounded raster headers before allowing browser decoding
 *   - Serialize decoding across all drawer generations and cancel queued work
 *   - Return small PNG Blobs without changing the original upload File
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/**
 * Admit at most 24 MiB of encoded source to the preview decoder.
 * Type: number. Units: bytes. Scope: one local preview source.
 * Consumers: requestGalleryUploadThumbnail.
 * Rationale: preview admission must not allocate unusually large encoded sources.
 */
const MAX_SOURCE_BYTES = 24 * 1024 * 1024;
/**
 * Admit at most 16 megapixels expressed as 16 * 1024 * 1024 pixels.
 * Type: number. Units: pixels. Scope: one decoded preview source.
 * Consumers: boundedDimensions before decoding and before canvas allocation.
 * Rationale: one RGBA source has at most 64 MiB of pixel data; codec overhead is browser-owned.
 */
const MAX_SOURCE_PIXELS = 16 * 1024 * 1024;
/**
 * Reject either source dimension above 8192 pixels, including very thin panoramas.
 * Type: number. Units: pixels. Scope: one preview source dimension.
 * Consumers: boundedDimensions.
 * Rationale: avoid pathological canvas/decoder dimensions despite a small pixel product.
 */
const MAX_SOURCE_EDGE = 8192;
/**
 * Inspect only the first 256 KiB of source headers without full-file byte copies.
 * Type: number. Units: bytes. Scope: one header probe.
 * Consumers: buildThumbnail and inspectGalleryUploadHeader.
 * Rationale: unknown or unusually metadata-heavy files keep an icon rather than unbounded parsing.
 */
const MAX_HEADER_BYTES = 256 * 1024;
/**
 * Limit the miniature's longest side to 256 pixels without enlarging smaller images.
 * Type: number. Units: pixels. Scope: one retained miniature.
 * Consumers: buildThumbnail.
 * Rationale: twelve RGBA miniature surfaces require at most 3 MiB of pixel data.
 */
const MAX_THUMBNAIL_EDGE = 256;
/**
 * Hold at most twelve waiting requests in addition to the single running decode.
 * Type: number. Units: queued requests. Scope: this document-wide scheduler.
 * Consumers: requestGalleryUploadThumbnail.
 * Rationale: replaced drawers must not build an unlimited backlog behind a slow decoder.
 */
const MAX_PENDING_JOBS = 12;

/** Public admission values shared with the selection renderer and regression fixtures. */
export const galleryUploadPreviewPolicy = Object.freeze({
    maxSourceBytes: MAX_SOURCE_BYTES,
    maxSourcePixels: MAX_SOURCE_PIXELS,
    maxSourceEdge: MAX_SOURCE_EDGE,
    maxHeaderBytes: MAX_HEADER_BYTES,
    maxThumbnailEdge: MAX_THUMBNAIL_EDGE,
});

/**
 * One scheduler request; cancellation drops its File reference before it starts.
 * @typedef {Object} UploadThumbnailJob
 * @property {File|null} file Original source, released on cancellation or dequeue.
 * @property {AbortSignal} signal Current item/render lifetime.
 * @property {function(Blob|null): void} finish Resolve with a miniature or a safe icon fallback.
 */
/** @type {Set<UploadThumbnailJob>} Pending requests in arrival order. */
const pendingJobs = new Set();
let decoding = false;

/**
 * Admit nonzero source geometry without trusting a compressed byte count alone.
 * @param {number} width Declared or decoded width in pixels.
 * @param {number} height Declared or decoded height in pixels.
 * @return {{width: number, height: number}|null} Bounded geometry, or no preview admission.
 */
function boundedDimensions(width, height) {
    return width > 0 && height > 0 && width <= MAX_SOURCE_EDGE && height <= MAX_SOURCE_EDGE
        && width * height <= MAX_SOURCE_PIXELS ? {width, height} : null;
}

/**
 * Inspect static PNG, JPEG and WebP headers before any image decoding.
 * Animated, malformed, unknown and metadata-heavy sources deliberately use icons.
 * @param {Uint8Array} bytes Bounded source prefix; trailing compressed bytes are never expanded here.
 * @return {{width: number, height: number}|null} Admitted raster dimensions, or an icon fallback.
 */
export function inspectGalleryUploadHeader(bytes) {
    const limit = Math.min(bytes.length, MAX_HEADER_BYTES);
    const data = new DataView(bytes.buffer, bytes.byteOffset, limit);
    const ascii = (offset, count) => String.fromCharCode(...bytes.subarray(offset, offset + count));
    if (limit >= 33 && ascii(1, 3) === 'PNG' && bytes[0] === 137
        && data.getUint32(4) === 0x0d0a1a0a && data.getUint32(8) === 13 && ascii(12, 4) === 'IHDR') {
        const dimensions = boundedDimensions(data.getUint32(16), data.getUint32(20));
        if (!dimensions) return null;
        for (let offset = 33; offset + 8 <= limit;) {
            const length = data.getUint32(offset);
            const type = ascii(offset + 4, 4);
            if (type === 'acTL' || type === 'IHDR' || type === 'IEND') return null;
            if (type === 'IDAT') return dimensions;
            if (length > limit - offset - 12) return null;
            offset += length + 12;
        }
        return null;
    }
    if (limit >= 4 && bytes[0] === 0xff && bytes[1] === 0xd8) {
        let dimensions = null;
        for (let offset = 2; offset < limit;) {
            if (bytes[offset++] !== 0xff) return null;
            while (offset < limit && bytes[offset] === 0xff) offset++;
            if (offset >= limit) return null;
            const marker = bytes[offset++];
            if (marker === 0xda) return dimensions;
            if (marker === 0xd9 || marker === 0x00 || offset + 2 > limit) return null;
            const length = data.getUint16(offset);
            if (length < 2 || offset + length > limit) return null;
            if (marker >= 0xc0 && marker <= 0xcf && ![0xc4, 0xc8, 0xcc].includes(marker)) {
                if (![0xc0, 0xc1, 0xc2].includes(marker) || dimensions || length < 8) return null;
                dimensions = boundedDimensions(data.getUint16(offset + 5), data.getUint16(offset + 3));
                if (!dimensions || bytes[offset + 7] === 0 || length < 8 + 3 * bytes[offset + 7]) return null;
            }
            offset += length;
        }
        return null;
    }
    if (limit >= 20 && ascii(0, 4) === 'RIFF' && ascii(8, 4) === 'WEBP') {
        let canvas = null;
        for (let offset = 12; offset + 8 <= limit;) {
            const type = ascii(offset, 4);
            const length = data.getUint32(offset + 4, true);
            const start = offset + 8;
            let dimensions = null;
            if (type === 'ANIM' || type === 'ANMF') return null;
            if (type === 'VP8X') {
                if (canvas || length !== 10 || start + 10 > limit || (bytes[start] & 2)) return null;
                const width = 1 + bytes[start + 4] + (bytes[start + 5] << 8) + (bytes[start + 6] << 16);
                const height = 1 + bytes[start + 7] + (bytes[start + 8] << 8) + (bytes[start + 9] << 16);
                canvas = boundedDimensions(width, height);
                if (!canvas) return null;
            } else if (type === 'VP8 ' && length >= 10 && start + 10 <= limit
                && !(bytes[start] & 1) && ascii(start + 3, 3) === '\x9d\x01\x2a') {
                dimensions = boundedDimensions(data.getUint16(start + 6, true) & 0x3fff,
                    data.getUint16(start + 8, true) & 0x3fff);
            } else if (type === 'VP8L' && length >= 5 && start + 5 <= limit && bytes[start] === 0x2f) {
                const bits = data.getUint32(start + 1, true);
                if (bits >>> 29) return null;
                dimensions = boundedDimensions((bits & 0x3fff) + 1, ((bits >>> 14) & 0x3fff) + 1);
            }
            if (type === 'VP8 ' || type === 'VP8L') {
                if (canvas && (!dimensions || canvas.width !== dimensions.width || canvas.height !== dimensions.height)) return null;
                return dimensions;
            }
            if (length > limit - start) return null;
            offset = start + length + (length & 1);
        }
    }
    return null;
}

/**
 * Produce one small PNG and close the full-resolution bitmap even on failure.
 * Missing bitmap/canvas support is a permanent progressive-enhancement fallback;
 * the preview owner never changes upload eligibility to require these APIs.
 * @param {File} file Original upload source.
 * @param {AbortSignal} signal Item lifetime, checked before and after asynchronous boundaries.
 * @return {Promise<Blob|null>} Small preview, or null for unsupported, failed or cancelled work.
 */
async function buildThumbnail(file, signal) {
    if (signal.aborted || typeof createImageBitmap !== 'function') return null;
    const bytes = new Uint8Array(await file.slice(0, MAX_HEADER_BYTES).arrayBuffer());
    if (signal.aborted || !inspectGalleryUploadHeader(bytes)) return null;
    const bitmap = await createImageBitmap(file);
    let canvas = null;
    try {
        if (signal.aborted || !boundedDimensions(bitmap.width, bitmap.height)) return null;
        canvas = document.createElement('canvas');
        const scale = Math.min(1, MAX_THUMBNAIL_EDGE / Math.max(bitmap.width, bitmap.height));
        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        const context = canvas.getContext('2d');
        if (!context) return null;
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
        return signal.aborted ? null : blob;
    } finally {
        bitmap.close();
        if (canvas) { canvas.width = 0; canvas.height = 0; }
    }
}

/**
 * Run exactly one decode at a time, including cancelled work still inside a codec.
 * Cancellation cannot interrupt createImageBitmap, so the slot is not released
 * until its real completion; a timeout must not start overlapping hidden decodes.
 * @return {Promise<void>} Drain admitted work without exposing decoder errors to upload.
 */
async function drainThumbnailJobs() {
    if (decoding) return;
    decoding = true;
    try {
        while (pendingJobs.size) {
            const job = pendingJobs.values().next().value;
            pendingJobs.delete(job);
            const file = job.file;
            job.file = null;
            let blob = null;
            try {
                if (file && !job.signal.aborted) blob = await buildThumbnail(file, job.signal);
            } catch { /* Unreadable or unsupported sources retain their upload File and icon. */ }
            job.finish(blob);
        }
    } finally {
        decoding = false;
    }
}

/**
 * Request a bounded miniature, promptly releasing cancelled pending File references.
 * @param {File} file Selected original; its bytes and metadata remain unchanged.
 * @param {AbortSignal} signal Cancellation scope belonging to the requesting tile generation.
 * @return {Promise<Blob|null>} Preview Blob, or null on cancellation, admission failure or queue saturation.
 */
export function requestGalleryUploadThumbnail(file, signal) {
    if (signal.aborted || file.size <= 0 || file.size > MAX_SOURCE_BYTES || pendingJobs.size >= MAX_PENDING_JOBS) {
        return Promise.resolve(null);
    }
    return new Promise(resolve => {
        const finish = blob => {
            signal.removeEventListener('abort', cancel);
            pendingJobs.delete(job);
            job.file = null;
            resolve(signal.aborted ? null : blob);
        };
        const cancel = () => finish(null);
        const job = {file, signal, finish};
        pendingJobs.add(job);
        signal.addEventListener('abort', cancel, {once: true});
        void drainThumbnailJobs();
    });
}
