/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/experimental-upload-worker.js
 * Module Type: Browser Worker
 *
 * Purpose:
 *   Performs image decoding and thumbnail encoding away from the main thread for
 *   the experimental browser-side upload path.
 *
 * Responsibilities:
 *   - Decode one browser-readable image file
 *   - Generate configured thumbnail sizes with OffscreenCanvas
 *   - Return Blob variants to the coordinator for ZIP packaging
 *   - Parse store-only source ZIP chunks for thumbnail rebuild jobs
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
 *   2026-06-10
 */

self.addEventListener('message', (event) => {
    const payload = event.data || {};
    const action = String(payload.action || 'prepare');
    if (action === 'zip') {
        createStoreOnlyZipBlob(payload.entries || []).then((zipBlob) => {
            self.postMessage({ok: true, id: payload.id, zipBlob});
        }).catch((error) => {
            self.postMessage({
                ok: false,
                id: payload.id,
                error: error instanceof Error ? error.message : String(error),
            });
        });
        return;
    }
    if (action === 'parseZip') {
        parseStoreOnlyZipBlob(payload.zipBlob).then((parsed) => {
            self.postMessage({ok: true, id: payload.id, parsed});
        }).catch((error) => {
            self.postMessage({
                ok: false,
                id: payload.id,
                error: error instanceof Error ? error.message : String(error),
            });
        });
        return;
    }
    if (action === 'prepareRebuildImage') {
        processRebuildImage(payload).catch((error) => {
            self.postMessage({
                ok: false,
                id: payload.id,
                error: error instanceof Error ? error.message : String(error),
            });
        });
        return;
    }
    processUploadImage(payload).catch((error) => {
        self.postMessage({
            ok: false,
            id: payload.id,
            error: error instanceof Error ? error.message : String(error),
        });
    });
});

/**
 * Process one image file and return generated variants.
 *
 * @param {Record<string, *>} payload Worker payload.
 */
async function processUploadImage(payload) {
    if (!self.OffscreenCanvas || !self.createImageBitmap) {
        throw new Error('OffscreenCanvas and createImageBitmap are required.');
    }
    const file = payload.file;
    if (!(file instanceof File)) {
        throw new Error('The worker did not receive a valid File object.');
    }
    const bitmap = await self.createImageBitmap(file, {imageOrientation: 'from-image'});
    try {
        const sizes = Array.isArray(payload.sizes) ? payload.sizes.map(Number).filter((size) => Number.isInteger(size) && size > 0) : [];
        const formats = Array.isArray(payload.formats) ? payload.formats.map(String).filter((format) => ['jpg', 'webp'].includes(format)) : [];
        const preparedName = safePreparedFilename(file.name || `image-${payload.id}.jpg`);
        const itemId = `item-${payload.id}`;
        const variants = [];
        for (const size of sizes) {
            const dimensions = expectedDimensions(bitmap.width, bitmap.height, size);
            const canvas = new OffscreenCanvas(dimensions.width, dimensions.height);
            const context = canvas.getContext('2d', {alpha: true});
            if (!context) {
                throw new Error('Could not create OffscreenCanvas 2D context.');
            }
            context.drawImage(bitmap, 0, 0, dimensions.width, dimensions.height);
            for (const format of formats) {
                const mimeType = format === 'webp' ? 'image/webp' : 'image/jpeg';
                const quality = format === 'webp' ? Number(payload.webpQuality || 0.82) : Number(payload.jpegQuality || 0.82);
                const blob = await canvas.convertToBlob({type: mimeType, quality});
                variants.push({
                    size,
                    format,
                    width: dimensions.width,
                    height: dimensions.height,
                    path: `thumbs/${itemId}/${size}.${format}`,
                    blob,
                });
            }
        }
        self.postMessage({
            ok: true,
            id: payload.id,
            item: {
                originalName: file.name,
                preparedName,
                originalPath: `originals/${itemId}-${preparedName}`,
                originalFile: file,
                variants,
            },
        });
    } finally {
        if (typeof bitmap.close === 'function') {
            bitmap.close();
        }
    }
}


/**
 * Process one downloaded source image for the experimental thumbnail rebuild path.
 *
 * @param {Record<string, *>} payload Worker payload.
 * @return {Promise<void>} Completion promise.
 */
async function processRebuildImage(payload) {
    if (!self.OffscreenCanvas || !self.createImageBitmap) {
        throw new Error('OffscreenCanvas and createImageBitmap are required.');
    }
    const blob = payload.blob;
    if (!(blob instanceof Blob)) {
        throw new Error('The worker did not receive a valid source image Blob.');
    }
    const bitmap = await self.createImageBitmap(blob, {imageOrientation: 'from-image'});
    try {
        const sizes = Array.isArray(payload.sizes) ? payload.sizes.map(Number).filter((size) => Number.isInteger(size) && size > 0) : [];
        const formats = Array.isArray(payload.formats) ? payload.formats.map(String).filter((format) => ['jpg', 'webp'].includes(format)) : [];
        const expectedVariants = Math.max(1, Number(payload.expectedVariants || (sizes.length * formats.length)));
        const imageId = Number.parseInt(String(payload.imageId || 0), 10);
        const itemId = `image-${imageId || payload.id}`;
        const variants = [];
        for (const size of sizes) {
            const dimensions = expectedDimensions(bitmap.width, bitmap.height, size);
            const canvas = new OffscreenCanvas(dimensions.width, dimensions.height);
            const context = canvas.getContext('2d', {alpha: true});
            if (!context) {
                throw new Error('Could not create OffscreenCanvas 2D context.');
            }
            context.drawImage(bitmap, 0, 0, dimensions.width, dimensions.height);
            for (const format of formats) {
                const mimeType = format === 'webp' ? 'image/webp' : 'image/jpeg';
                const quality = format === 'webp' ? Number(payload.webpQuality || 0.82) : Number(payload.jpegQuality || 0.82);
                const blobVariant = await canvas.convertToBlob({type: mimeType, quality});
                if (!(blobVariant instanceof Blob) || blobVariant.size <= 0 || (blobVariant.type && blobVariant.type.toLowerCase() !== mimeType)) {
                    throw new Error(`Browser encoder did not return a valid ${format.toUpperCase()} thumbnail.`);
                }
                variants.push({
                    size,
                    format,
                    width: dimensions.width,
                    height: dimensions.height,
                    path: `thumbs/${itemId}/${size}.${format}`,
                    blob: blobVariant,
                });
            }
        }
        self.postMessage({
            ok: true,
            id: payload.id,
            item: {
                imageId,
                filename: String(payload.filename || ''),
                entryPath: String(payload.entryPath || ''),
                targetFormats: formats,
                expectedVariants,
                variants,
            },
        });
    } finally {
        if (typeof bitmap.close === 'function') {
            bitmap.close();
        }
    }
}

/**
 * Return dimensions preserving aspect ratio without upscaling.
 *
 * @param {number} sourceWidth Source width.
 * @param {number} sourceHeight Source height.
 * @param {number} maxSide Maximum side.
 * @return {{width: number, height: number} } Expected dimensions.
 */
function expectedDimensions(sourceWidth, sourceHeight, maxSide) {
    const width = Math.max(1, Number(sourceWidth) || 1);
    const height = Math.max(1, Number(sourceHeight) || 1);
    const scale = Math.min(1, Number(maxSide) / Math.max(width, height));
    return {
        width: Math.max(1, Math.round(width * scale)),
        height: Math.max(1, Math.round(height * scale)),
    };
}

/**
 * Create a conservative filename compatible with the PHP-side sanitizer.
 *
 * @param {string} filename Original filename.
 * @return {string} Prepared filename.
 */
function safePreparedFilename(filename) {
    const dot = filename.lastIndexOf('.');
    const extension = dot >= 0 ? filename.slice(dot + 1).toLowerCase().replace(/[^a-z0-9]/g, '') : 'jpg';
    const base = dot >= 0 ? filename.slice(0, dot) : filename;
    const slug = base.toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '') || 'image';
    return `${slug}.${extension || 'jpg'}`;
}

/**
 * Create a store-only ZIP blob from named blob entries.
 *
 * @param {*} entries Entries value.
 * @return {Promise<Blob>} ZIP blob.
 */
async function createStoreOnlyZipBlob(entries) {
    const encoder = new TextEncoder();
    const localParts = [];
    const centralParts = [];
    let offset = 0;
    const timestamp = new Date();
    for (const entry of entries) {
        const path = String(entry.path || '').replace(/^\/+/, '');
        if (!path || !(entry.blob instanceof Blob)) {
            throw new Error('ZIP entries must include a path and Blob payload.');
        }
        const pathBytes = encoder.encode(path);
        const data = new Uint8Array(await entry.blob.arrayBuffer());
        const crc = crc32(data);
        const localHeader = new Uint8Array(30 + pathBytes.length);
        const localView = new DataView(localHeader.buffer);
        localView.setUint32(0, 0x04034b50, true);
        localView.setUint16(4, 20, true);
        localView.setUint16(6, 0x0800, true);
        localView.setUint16(8, 0, true);
        localView.setUint16(10, dosTime(timestamp), true);
        localView.setUint16(12, dosDate(timestamp), true);
        localView.setUint32(14, crc, true);
        localView.setUint32(18, data.length, true);
        localView.setUint32(22, data.length, true);
        localView.setUint16(26, pathBytes.length, true);
        localView.setUint16(28, 0, true);
        localHeader.set(pathBytes, 30);
        localParts.push(localHeader, data);

        const centralHeader = new Uint8Array(46 + pathBytes.length);
        const centralView = new DataView(centralHeader.buffer);
        centralView.setUint32(0, 0x02014b50, true);
        centralView.setUint16(4, 20, true);
        centralView.setUint16(6, 20, true);
        centralView.setUint16(8, 0x0800, true);
        centralView.setUint16(10, 0, true);
        centralView.setUint16(12, dosTime(timestamp), true);
        centralView.setUint16(14, dosDate(timestamp), true);
        centralView.setUint32(16, crc, true);
        centralView.setUint32(20, data.length, true);
        centralView.setUint32(24, data.length, true);
        centralView.setUint16(28, pathBytes.length, true);
        centralView.setUint16(30, 0, true);
        centralView.setUint16(32, 0, true);
        centralView.setUint16(34, 0, true);
        centralView.setUint16(36, 0, true);
        centralView.setUint32(38, 0, true);
        centralView.setUint32(42, offset, true);
        centralHeader.set(pathBytes, 46);
        centralParts.push(centralHeader);
        offset += localHeader.length + data.length;
    }
    const centralSize = centralParts.reduce((sum, part) => sum + part.length, 0);
    const endHeader = new Uint8Array(22);
    const endView = new DataView(endHeader.buffer);
    endView.setUint32(0, 0x06054b50, true);
    endView.setUint16(8, entries.length, true);
    endView.setUint16(10, entries.length, true);
    endView.setUint32(12, centralSize, true);
    endView.setUint32(16, offset, true);
    endView.setUint16(20, 0, true);
    return new Blob([...localParts, ...centralParts, endHeader], {type: 'application/zip'});
}


/**
 * Parse a store-only ZIP blob without inflating the full archive into memory.
 *
 * @param {Blob} zipBlob ZIP payload.
 * @return {Promise<{manifest: Record<string, *>, entries: Array<{path: string, blob: Blob} >}>} Parsed archive.
 */
async function parseStoreOnlyZipBlob(zipBlob) {
    if (!(zipBlob instanceof Blob)) {
        throw new Error('ZIP parser expected a Blob payload.');
    }
    const decoder = new TextDecoder();
    let offset = 0;
    const entries = [];
    let manifest = null;
    while (offset + 4 <= zipBlob.size) {
        const fixedHeader = new Uint8Array(await zipBlob.slice(offset, offset + 30).arrayBuffer());
        if (fixedHeader.length < 4) {
            break;
        }
        const view = new DataView(fixedHeader.buffer);
        const signature = view.getUint32(0, true);
        if (signature === 0x02014b50 || signature === 0x06054b50) {
            break;
        }
        if (signature !== 0x04034b50) {
            throw new Error('Unsupported ZIP structure.');
        }
        if (fixedHeader.length < 30) {
            throw new Error('Truncated ZIP local header.');
        }
        const flags = view.getUint16(6, true);
        const method = view.getUint16(8, true);
        const compressedSize = view.getUint32(18, true);
        const uncompressedSize = view.getUint32(22, true);
        const nameLength = view.getUint16(26, true);
        const extraLength = view.getUint16(28, true);
        if ((flags & 0x08) !== 0 || method !== 0 || compressedSize !== uncompressedSize) {
            throw new Error('Only store-only ZIP entries are supported.');
        }
        const nameStart = offset + 30;
        const nameEnd = nameStart + nameLength;
        const dataStart = nameEnd + extraLength;
        const dataEnd = dataStart + compressedSize;
        if (nameLength <= 0 || dataEnd > zipBlob.size) {
            throw new Error('Invalid ZIP entry boundary.');
        }
        const name = decoder.decode(await zipBlob.slice(nameStart, nameEnd).arrayBuffer()).replace(/^\/+/, '');
        if (name && !name.endsWith('/')) {
            const payload = zipBlob.slice(dataStart, dataEnd);
            if (name === 'manifest.json') {
                manifest = JSON.parse(await payload.text());
            } else {
                entries.push({path: name, blob: payload});
            }
        }
        offset = dataEnd;
    }
    if (!manifest || typeof manifest !== 'object') {
        throw new Error('ZIP manifest is missing.');
    }
    return {manifest, entries};
}

/**
 * Calculate CRC32 for a ZIP entry payload.
 *
 * @param {Uint8Array} data Entry payload.
 * @return {number} Unsigned CRC32.
 */
function crc32(data) {
    const table = crc32Table();
    let crc = -1;
    for (let index = 0; index < data.length; index++) {
        crc = (crc >>> 8) ^ table[(crc ^ data[index]) & 0xff];
    }
    return (crc ^ -1) >>> 0;
}

/**
 * Return a cached CRC32 lookup table.
 *
 * @return {Uint32Array} CRC32 table.
 */
function crc32Table() {
    if (self.__experimentalUploadCrcTable) {
        return self.__experimentalUploadCrcTable;
    }
    const table = new Uint32Array(256);
    for (let value = 0; value < 256; value++) {
        let crc = value;
        for (let bit = 0; bit < 8; bit++) {
            crc = crc & 1 ? 0xedb88320 ^ (crc >>> 1) : crc >>> 1;
        }
        table[value] = crc >>> 0;
    }
    self.__experimentalUploadCrcTable = table;
    return table;
}

/**
 * Return DOS time for ZIP headers.
 *
 * @param {Date} date Timestamp.
 * @return {number} DOS time.
 */
function dosTime(date) {
    return (date.getHours() << 11) | (date.getMinutes() << 5) | Math.floor(date.getSeconds() / 2);
}

/**
 * Return DOS date for ZIP headers.
 *
 * @param {Date} date Timestamp.
 * @return {number} DOS date.
 */
function dosDate(date) {
    return ((date.getFullYear() - 1980) << 9) | ((date.getMonth() + 1) << 5) | date.getDate();
}

