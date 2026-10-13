/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/browser-image-worker.js
 * Module Type: Browser Worker
 *
 * Purpose:
 *   Performs image decoding and thumbnail encoding away from the main thread for
 *   the browser-side upload path.
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

/**
 * Bound retained extracted originals until the existing upload batcher stages them.
 * Type: number.
 * Units: bytes. Scope: one eager ZIP selection or one streamed image.
 * Consumers: extractUploadZipEntries, inspectUploadZipEntries and the coordinator's remaining retention budget.
 * Rationale: the legacy File-array handoff must reject larger selections before
 * extraction rather than retain gigabytes; the incremental path applies the
 * same ceiling to each image before allocating its verified payload.
 */
const uploadZipRetainedBytes = 64 * 1024 * 1024;
/**
 * Bound metadata allocated while reading the classic ZIP central directory.
 * Type: number.
 * Units: bytes. Scope: one selected archive.
 * Consumers: findZipEndRecord.
 * Rationale: 10,000 entries do not bound arbitrary comments/extra fields by themselves.
 */
const uploadZipDirectoryBytes = 4 * 1024 * 1024;
/** Admit only bounded zero padding after an otherwise complete ZIP end record.
 * Type: number. Units: bytes. Scope: one source archive's final suffix.
 * Consumers: findZipEndRecord. Rationale: Apple's observed exports append 205 zero bytes; a 4 KiB cap covers that suffix while refusing nonzero trailers or unbounded padding.
 */
const uploadZipZeroPaddingBytes = 4 * 1024;
/** Bound the end-record read including the maximum classic comment and accepted zero suffix.
 * Type: number. Units: bytes. Scope: one archive tail read.
 * Consumers: findZipEndRecord. Rationale: 65,557 standard end/comment bytes plus 4 KiB padding keep large-source inspection bounded.
 */
const uploadZipTailBytes = 65557 + uploadZipZeroPaddingBytes;

/** Describe an admitted central-directory entry before reading its image bytes.
 * @typedef {{name:string,path:string,localOffset:number,compressedSize:number,uncompressedSize:number,method:number,expectedCrc:number}} UploadZipEntry
 */
/** @type {{blob:Blob,entries:UploadZipEntry[]}|null} One dedicated reader worker owns an immutable archive session. */
let uploadZipSession = null;

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
    if (action === 'openUploadZip' || action === 'readUploadZipEntry') {
        (async () => {
            if (action === 'openUploadZip') {
                const inspected = await inspectUploadZipEntries(payload.zipBlob, payload.limits || {});
                uploadZipSession = {blob: payload.zipBlob, entries: inspected.entries};
                return {entries: inspected.entries.map((entry, index) => ({index, name: entry.name, path: entry.path, size: entry.uncompressedSize})), skipped: inspected.skipped, reasons: inspected.reasons};
            }
            const index = Number(payload.entryIndex);
            if (!uploadZipSession || !Number.isInteger(index) || index < 0 || index >= uploadZipSession.entries.length) throw uploadZipFailure('invalid', 'Invalid archive reader cursor.');
            return {entry: await readUploadZipImage(uploadZipSession.blob, uploadZipSession.entries[index])};
        })().then(result => self.postMessage({ok: true, id: payload.id, result})).catch(error => self.postMessage({ok: false, id: payload.id, code: String(error.code || 'invalid'), error: error instanceof Error ? error.message : 'Archive reader failed.'}));
        return;
    }
    if (action === 'extractUploadZip') {
        extractUploadZipEntries(payload.zipBlob, payload.limits || {}).then((result) => {
            self.postMessage({ok: true, id: payload.id, result});
        }).catch((error) => {
            self.postMessage({
                ok: false,
                id: payload.id,
                error: error instanceof Error ? error.message : String(error),
                code: String(error.code || 'invalid'),
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
    const clientExif = await readClientExifMetadata(file);
    const originalSignature = new Uint8Array(await file.slice(0, 16).arrayBuffer());
    const detectedOriginalFormat = detectImageFormatFromSignature(originalSignature);
    if (!detectedOriginalFormat) {
        throw new Error(`Unsupported original image signature for ${file.name || 'selected file'}. Use the default server-side upload path for this file.`);
    }
    const bitmap = await self.createImageBitmap(file, {imageOrientation: 'from-image'});
    try {
        const sizes = Array.isArray(payload.sizes) ? payload.sizes.map(Number).filter((size) => Number.isInteger(size) && size > 0) : [];
        const formats = Array.isArray(payload.formats) ? payload.formats.map(String).filter((format) => ['jpg', 'webp'].includes(format)) : [];
        const preparedName = safePreparedFilename(file.name || `image-${payload.id}.${detectedOriginalFormat}`, detectedOriginalFormat);
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
                sourceIndex: Number(payload.id || 0),
                originalName: file.name,
                preparedName,
                originalPath: `originals/${itemId}-${preparedName}`,
                originalWidth: bitmap.width,
                originalHeight: bitmap.height,
                originalDisplayWidth: bitmap.width,
                originalDisplayHeight: bitmap.height,
                originalExifOrientation: Number(clientExif?.exif_orientation || 1),
                originalMime: file.type || mimeFromFilename(file.name),
                originalDetectedFormat: detectedOriginalFormat,
                originalSize: file.size || 0,
                originalFile: file,
                clientExif,
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
 * Process one downloaded source image for the browser thumbnail rebuild path.
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
 * Infer a browser MIME value from a filename when File.type is empty.
 *
 * @param {string} filename Original filename.
 * @return {string} MIME value or an empty string.
 */
function mimeFromFilename(filename) {
    const extension = String(filename || '').split('.').pop()?.toLowerCase() || '';
    if (extension === 'jpg' || extension === 'jpeg') {
        return 'image/jpeg';
    }
    if (extension === 'png') {
        return 'image/png';
    }
    if (extension === 'gif') {
        return 'image/gif';
    }
    if (extension === 'webp') {
        return 'image/webp';
    }
    return '';
}

/**
 * Read compact EXIF and GPS metadata from a JPEG source file in the browser.
 *
 * @param {File} file Source image file.
 * @return {Promise<Record<string, *> | null>} Manifest-safe metadata or null.
 */
async function readClientExifMetadata(file) {
    const mime = String(file.type || mimeFromFilename(file.name)).toLowerCase();
    const extension = String(file.name || '').split('.').pop()?.toLowerCase() || '';
    if (mime !== 'image/jpeg' && extension !== 'jpg' && extension !== 'jpeg') {
        return null;
    }
    try {
        const sliceSize = Math.min(Number(file.size || 0), 2 * 1024 * 1024);
        if (sliceSize <= 4) {
            return null;
        }
        const buffer = await file.slice(0, sliceSize).arrayBuffer();
        return parseJpegExifMetadata(new DataView(buffer));
    } catch (error) {
        return null;
    }
}

/**
 * Parse compact EXIF/GPS metadata from JPEG APP1 bytes.
 *
 * @param {DataView} view JPEG byte view.
 * @return {Record<string, *> | null} Manifest-safe metadata or null.
 */
function parseJpegExifMetadata(view) {
    if (view.byteLength < 12 || view.getUint8(0) !== 0xff || view.getUint8(1) !== 0xd8) {
        return null;
    }
    let offset = 2;
    while (offset + 4 <= view.byteLength) {
        if (view.getUint8(offset) !== 0xff) {
            return null;
        }
        let marker = view.getUint8(offset + 1);
        offset += 2;
        while (marker === 0xff && offset < view.byteLength) {
            marker = view.getUint8(offset);
            offset++;
        }
        if (marker === 0xda || marker === 0xd9) {
            break;
        }
        if (offset + 2 > view.byteLength) {
            break;
        }
        const segmentLength = view.getUint16(offset, false);
        const segmentStart = offset + 2;
        const segmentEnd = offset + segmentLength;
        if (segmentLength < 2 || segmentEnd > view.byteLength) {
            break;
        }
        if (marker === 0xe1 && segmentLength >= 8 && hasAsciiSignature(view, segmentStart, 'Exif\0\0')) {
            return parseTiffExifMetadata(view, segmentStart + 6, segmentEnd);
        }
        offset = segmentEnd;
    }
    return null;
}

/**
 * Parse EXIF data stored as TIFF inside JPEG APP1.
 *
 * @param {DataView} view JPEG byte view.
 * @param {number} tiffStart TIFF header offset.
 * @param {number} segmentEnd APP1 segment end offset.
 * @return {Record<string, *> | null} Manifest-safe metadata or null.
 */
function parseTiffExifMetadata(view, tiffStart, segmentEnd) {
    if (tiffStart + 8 > segmentEnd) {
        return null;
    }
    const byteOrder = String.fromCharCode(view.getUint8(tiffStart), view.getUint8(tiffStart + 1));
    const littleEndian = byteOrder === 'II';
    if (!littleEndian && byteOrder !== 'MM') {
        return null;
    }
    if (view.getUint16(tiffStart + 2, littleEndian) !== 42) {
        return null;
    }
    const ifd0Offset = view.getUint32(tiffStart + 4, littleEndian);
    const ifd0 = readExifIfd(view, tiffStart, tiffStart + ifd0Offset, segmentEnd, littleEndian);
    if (!ifd0) {
        return null;
    }
    const exifIfdOffset = numberFromExifValue(ifd0.get(0x8769));
    const gpsIfdOffset = numberFromExifValue(ifd0.get(0x8825));
    const exifIfd = exifIfdOffset > 0 ? readExifIfd(view, tiffStart, tiffStart + exifIfdOffset, segmentEnd, littleEndian) : null;
    const gpsIfd = gpsIfdOffset > 0 ? readExifIfd(view, tiffStart, tiffStart + gpsIfdOffset, segmentEnd, littleEndian) : null;
    const gps = gpsIfd ? gpsMetadataFromIfd(gpsIfd) : {};
    const metadata = compactObject({
        exif_taken_at: exifDateToSql(firstString(exifIfd?.get(0x9003), exifIfd?.get(0x9004), ifd0.get(0x0132))),
        exif_camera_make: cleanExifString(ifd0.get(0x010f)),
        exif_camera_model: cleanExifString(ifd0.get(0x0110)),
        exif_lens_model: cleanExifString(exifIfd?.get(0xa434)),
        exif_focal_length: focalLengthText(exifIfd?.get(0x920a)),
        exif_aperture: apertureText(exifIfd?.get(0x829d)),
        exif_exposure_time: exposureTimeText(exifIfd?.get(0x829a)),
        exif_iso: integerFromExifValue(exifIfd?.get(0x8827)),
        gps_lat: gps.gps_lat,
        gps_lng: gps.gps_lng,
        gps_altitude: gps.gps_altitude,
        exif_orientation: normalizedExifOrientation(ifd0.get(0x0112)),
    });
    return Object.keys(metadata).length ? metadata : null;
}

/**
 * Read one EXIF IFD into a tag map.
 *
 * @param {DataView} view JPEG byte view.
 * @param {number} tiffStart TIFF header offset.
 * @param {number} ifdOffset IFD offset.
 * @param {number} segmentEnd APP1 segment end offset.
 * @param {boolean} littleEndian Whether TIFF data is little-endian.
 * @return {Map<number, *> | null} Tag map or null.
 */
function readExifIfd(view, tiffStart, ifdOffset, segmentEnd, littleEndian) {
    if (ifdOffset < tiffStart || ifdOffset + 2 > segmentEnd) {
        return null;
    }
    const count = view.getUint16(ifdOffset, littleEndian);
    const entriesStart = ifdOffset + 2;
    if (entriesStart + count * 12 > segmentEnd) {
        return null;
    }
    const tags = new Map();
    for (let index = 0; index < count; index++) {
        const entryOffset = entriesStart + index * 12;
        const tag = view.getUint16(entryOffset, littleEndian);
        const type = view.getUint16(entryOffset + 2, littleEndian);
        const valueCount = view.getUint32(entryOffset + 4, littleEndian);
        const value = readExifTagValue(view, tiffStart, entryOffset + 8, type, valueCount, segmentEnd, littleEndian);
        if (value !== null && value !== undefined) {
            tags.set(tag, value);
        }
    }
    return tags;
}

/**
 * Read one EXIF tag value according to TIFF type metadata.
 *
 * @param {DataView} view JPEG byte view.
 * @param {number} tiffStart TIFF header offset.
 * @param {number} valueFieldOffset Offset of the 4-byte value field.
 * @param {number} type TIFF field type.
 * @param {number} valueCount TIFF value count.
 * @param {number} segmentEnd APP1 segment end offset.
 * @param {boolean} littleEndian Whether TIFF data is little-endian.
 * @return {*} Decoded value.
 */
function readExifTagValue(view, tiffStart, valueFieldOffset, type, valueCount, segmentEnd, littleEndian) {
    const typeSizes = {1: 1, 2: 1, 3: 2, 4: 4, 5: 8, 7: 1, 9: 4, 10: 8};
    const unitSize = typeSizes[type] || 0;
    const totalSize = unitSize * valueCount;
    if (unitSize <= 0 || valueCount <= 0) {
        return null;
    }
    const dataOffset = totalSize <= 4 ? valueFieldOffset : tiffStart + view.getUint32(valueFieldOffset, littleEndian);
    if (dataOffset < tiffStart || dataOffset + totalSize > segmentEnd) {
        return null;
    }
    if (type === 2) {
        return asciiFromView(view, dataOffset, totalSize);
    }
    if (type === 1 || type === 7) {
        const values = [];
        for (let index = 0; index < valueCount; index++) {
            values.push(view.getUint8(dataOffset + index));
        }
        return valueCount === 1 ? values[0] : values;
    }
    if (type === 3) {
        const values = [];
        for (let index = 0; index < valueCount; index++) {
            values.push(view.getUint16(dataOffset + index * 2, littleEndian));
        }
        return valueCount === 1 ? values[0] : values;
    }
    if (type === 4) {
        const values = [];
        for (let index = 0; index < valueCount; index++) {
            values.push(view.getUint32(dataOffset + index * 4, littleEndian));
        }
        return valueCount === 1 ? values[0] : values;
    }
    if (type === 5 || type === 10) {
        const values = [];
        for (let index = 0; index < valueCount; index++) {
            const numerator = type === 10 ? view.getInt32(dataOffset + index * 8, littleEndian) : view.getUint32(dataOffset + index * 8, littleEndian);
            const denominator = type === 10 ? view.getInt32(dataOffset + index * 8 + 4, littleEndian) : view.getUint32(dataOffset + index * 8 + 4, littleEndian);
            values.push({numerator, denominator, value: denominator === 0 ? null : numerator / denominator});
        }
        return valueCount === 1 ? values[0] : values;
    }
    if (type === 9) {
        const values = [];
        for (let index = 0; index < valueCount; index++) {
            values.push(view.getInt32(dataOffset + index * 4, littleEndian));
        }
        return valueCount === 1 ? values[0] : values;
    }
    return null;
}

/**
 * Return true when bytes at offset match an ASCII signature.
 *
 * @param {DataView} view Byte view.
 * @param {number} offset Offset to inspect.
 * @param {string} signature Expected signature.
 * @return {boolean} True when bytes match.
 */
function hasAsciiSignature(view, offset, signature) {
    if (offset + signature.length > view.byteLength) {
        return false;
    }
    for (let index = 0; index < signature.length; index++) {
        if (view.getUint8(offset + index) !== signature.charCodeAt(index)) {
            return false;
        }
    }
    return true;
}

/**
 * Decode a null-terminated ASCII field.
 *
 * @param {DataView} view Byte view.
 * @param {number} offset Data offset.
 * @param {number} length Data length.
 * @return {string} Decoded string.
 */
function asciiFromView(view, offset, length) {
    let value = '';
    for (let index = 0; index < length; index++) {
        const charCode = view.getUint8(offset + index);
        if (charCode === 0) {
            break;
        }
        value += String.fromCharCode(charCode);
    }
    return value.trim();
}

/**
 * Return the first non-empty string among EXIF values.
 *
 * @param {...*} values Values to inspect.
 * @return {string} First string or empty string.
 */
function firstString(...values) {
    for (const value of values) {
        const cleaned = cleanExifString(value);
        if (cleaned) {
            return cleaned;
        }
    }
    return '';
}

/**
 * Normalize one EXIF ASCII value.
 *
 * @param {*} value Value to normalize.
 * @return {string} Clean string.
 */
function cleanExifString(value) {
    return String(Array.isArray(value) ? value[0] : value || '').replace(/\u0000/g, '').trim();
}

/**
 * Convert an EXIF datetime string to SQL datetime format.
 *
 * @param {string} value EXIF datetime value.
 * @return {string|null} SQL datetime or null.
 */
function exifDateToSql(value) {
    const match = String(value || '').trim().match(/^(\d{4}):(\d{2}):(\d{2})\s+(\d{2}):(\d{2}):(\d{2})/);
    if (!match) {
        return null;
    }
    return `${match[1]}-${match[2]}-${match[3]} ${match[4]}:${match[5]}:${match[6]}`;
}

/**
 * Return a normalized EXIF orientation value.
 *
 * @param {*} value Orientation tag value.
 * @return {number} Orientation from 1 to 8.
 */
function normalizedExifOrientation(value) {
    const orientation = Number(Array.isArray(value) ? value[0] : value || 1);
    return Number.isInteger(orientation) && orientation >= 1 && orientation <= 8 ? orientation : 1;
}

/**
 * Convert a rational EXIF field to a JavaScript number.
 *
 * @param {*} value EXIF value.
 * @return {number} Numeric value or zero.
 */
function numberFromExifValue(value) {
    if (Array.isArray(value)) {
        return numberFromExifValue(value[0]);
    }
    if (value && typeof value === 'object' && Object.prototype.hasOwnProperty.call(value, 'value')) {
        return Number(value.value || 0);
    }
    return Number(value || 0);
}

/**
 * Convert an EXIF field to an integer when possible.
 *
 * @param {*} value EXIF value.
 * @return {number|null} Integer value or null.
 */
function integerFromExifValue(value) {
    const number = numberFromExifValue(value);
    return Number.isFinite(number) && number > 0 ? Math.round(number) : null;
}

/**
 * Format a decimal value without noisy trailing zeroes.
 *
 * @param {number} value Numeric value.
 * @param {number} places Maximum decimals.
 * @return {string} Formatted value.
 */
function compactDecimal(value, places = 2) {
    if (!Number.isFinite(value)) {
        return '';
    }
    return value.toFixed(places).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
}

/**
 * Convert EXIF focal length to display text.
 *
 * @param {*} value EXIF focal length value.
 * @return {string|null} Display text or null.
 */
function focalLengthText(value) {
    const number = numberFromExifValue(value);
    return number > 0 ? `${compactDecimal(number, 1)} mm` : null;
}

/**
 * Convert EXIF aperture to display text.
 *
 * @param {*} value EXIF aperture value.
 * @return {string|null} Display text or null.
 */
function apertureText(value) {
    const number = numberFromExifValue(value);
    return number > 0 ? `f/${compactDecimal(number, 1)}` : null;
}

/**
 * Convert EXIF exposure time to display text.
 *
 * @param {*} value EXIF exposure time value.
 * @return {string|null} Display text or null.
 */
function exposureTimeText(value) {
    if (value && typeof value === 'object' && Number(value.numerator) > 0 && Number(value.denominator) > 0) {
        if (Number(value.numerator) === 1 && Number(value.denominator) > 1) {
            return `1/${Number(value.denominator)}`;
        }
        const numeric = Number(value.numerator) / Number(value.denominator);
        return numeric >= 1 ? `${compactDecimal(numeric, 1)} s` : `${Number(value.numerator)}/${Number(value.denominator)}`;
    }
    const number = numberFromExifValue(value);
    return number > 0 ? `${compactDecimal(number, 3)} s` : null;
}

/**
 * Convert GPS IFD tags to decimal coordinates.
 *
 * @param {Map<number, *>} gpsIfd GPS IFD tag map.
 * @return {Record<string, number|null>} GPS metadata.
 */
function gpsMetadataFromIfd(gpsIfd) {
    const latitude = gpsCoordinate(gpsIfd.get(0x0002), cleanExifString(gpsIfd.get(0x0001)));
    const longitude = gpsCoordinate(gpsIfd.get(0x0004), cleanExifString(gpsIfd.get(0x0003)));
    const altitudeValue = gpsAltitude(gpsIfd.get(0x0006), gpsIfd.get(0x0005));
    return compactObject({
        gps_lat: latitude !== null && latitude >= -90 && latitude <= 90 ? latitude : null,
        gps_lng: longitude !== null && longitude >= -180 && longitude <= 180 ? longitude : null,
        gps_altitude: altitudeValue,
    });
}

/**
 * Convert one GPS coordinate from degrees/minutes/seconds to decimal degrees.
 *
 * @param {*} value GPS rational value.
 * @param {string} ref Coordinate reference.
 * @return {number|null} Decimal coordinate or null.
 */
function gpsCoordinate(value, ref) {
    if (!Array.isArray(value) || value.length < 3) {
        return null;
    }
    const degrees = numberFromExifValue(value[0]);
    const minutes = numberFromExifValue(value[1]);
    const seconds = numberFromExifValue(value[2]);
    if (![degrees, minutes, seconds].every(Number.isFinite)) {
        return null;
    }
    let decimal = degrees + minutes / 60 + seconds / 3600;
    if (['S', 'W'].includes(String(ref || '').toUpperCase())) {
        decimal *= -1;
    }
    return Math.round(decimal * 10000000) / 10000000;
}

/**
 * Convert EXIF GPS altitude to meters.
 *
 * @param {*} value Altitude rational value.
 * @param {*} ref Altitude reference value.
 * @return {number|null} Altitude value or null.
 */
function gpsAltitude(value, ref) {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const altitude = numberFromExifValue(value);
    if (!Number.isFinite(altitude)) {
        return null;
    }
    const refValue = Array.isArray(ref) ? Number(ref[0] || 0) : Number(ref || 0);
    const signed = refValue === 1 ? -altitude : altitude;
    return Math.round(signed * 100) / 100;
}

/**
 * Remove null, empty string, and undefined values from an object.
 *
 * @param {Record<string, *>} value Object to compact.
 * @return {Record<string, *>} Compacted object.
 */
function compactObject(value) {
    const result = {};
    Object.entries(value || {}).forEach(([key, item]) => {
        if (item !== null && item !== undefined && item !== '') {
            result[key] = item;
        }
    });
    return result;
}

/**
 * Create a conservative filename compatible with the PHP-side sanitizer.
 *
 * @param {string} filename Original filename.
 * @param {string|null} detectedFormat Format detected from source bytes.
 * @return {string} Prepared filename.
 */
function safePreparedFilename(filename, detectedFormat = null) {
    const dot = filename.lastIndexOf('.');
    const rawExtension = dot >= 0 ? filename.slice(dot + 1).toLowerCase().replace(/[^a-z0-9]/g, '') : '';
    const extension = uploadExtensionForDetectedFormat(rawExtension || 'jpg', detectedFormat);
    const base = dot >= 0 ? filename.slice(0, dot) : filename;
    const slug = base.toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '') || 'image';
    return `${slug}.${extension}`;
}

/**
 * Return a supported extension for an original image signature.
 *
 * @param {string} extension Existing filename extension.
 * @param {string|null} detectedFormat Format detected from source bytes.
 * @return {string} Safe extension.
 */
function uploadExtensionForDetectedFormat(extension, detectedFormat) {
    const normalized = String(extension || '').toLowerCase();
    const detected = String(detectedFormat || '').toLowerCase();
    if (detected === 'jpg') {
        return ['jpg', 'jpeg'].includes(normalized) ? normalized : 'jpg';
    }
    if (['png', 'gif', 'webp'].includes(detected)) {
        return normalized === detected ? normalized : detected;
    }
    return ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(normalized) ? normalized : 'jpg';
}

/**
 * Detect an original image format from its leading file signature bytes.
 *
 * @param {Uint8Array} bytes Leading source bytes.
 * @return {string|null} Detected image format.
 */
function detectImageFormatFromSignature(bytes) {
    if (!(bytes instanceof Uint8Array) || bytes.length < 4) {
        return null;
    }
    if (bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff) {
        return 'jpg';
    }
    if (bytes.length >= 8 && bytes[0] === 0x89 && bytes[1] === 0x50 && bytes[2] === 0x4e && bytes[3] === 0x47 && bytes[4] === 0x0d && bytes[5] === 0x0a && bytes[6] === 0x1a && bytes[7] === 0x0a) {
        return 'png';
    }
    if (bytes.length >= 6) {
        const header = String.fromCharCode(bytes[0], bytes[1], bytes[2], bytes[3], bytes[4], bytes[5]);
        if (header === 'GIF87a' || header === 'GIF89a') {
            return 'gif';
        }
    }
    if (bytes.length >= 12) {
        const riff = String.fromCharCode(bytes[0], bytes[1], bytes[2], bytes[3]);
        const webp = String.fromCharCode(bytes[8], bytes[9], bytes[10], bytes[11]);
        if (riff === 'RIFF' && webp === 'WEBP') {
            return 'webp';
        }
    }
    return null;
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
 * Extract supported images from a standard user-selected ZIP archive.
 *
 * Central-directory sizes are authoritative, so data descriptors do not make
 * entry boundaries ambiguous. Unsupported and unsafe entries have counted reasons.
 * Retained images and inflated payloads are bounded before allocation.
 *
 * @param {Blob} zipBlob User-selected ZIP payload.
 * @param {{maximumEntries?:number,maximumEntryBytes?:number,maximumTotalBytes?:number,maximumRetainedBytes?:number}} limits Caller limits, clamped by worker safety ceilings.
 * @return {Promise<{entries:Array<{name:string,path:string,blob:Blob}>,skipped:number,totalEntries:number,reasons:Record<string,number>}>} Ordered valid images and rejection counts; malformed archives reject.
 */
async function extractUploadZipEntries(zipBlob, limits) {
    const inspected = await inspectUploadZipEntries(zipBlob, limits);
    const maximumRetainedBytes = Math.max(0, Math.min(Number(limits.maximumRetainedBytes ?? uploadZipRetainedBytes), uploadZipRetainedBytes));
    const entries = [];
    let retainedBytes = 0;
    for (const descriptor of inspected.entries) {
        if (retainedBytes + descriptor.uncompressedSize > maximumRetainedBytes) throw uploadZipFailure('retention', 'ZIP images exceed the bounded extraction budget. Select smaller archives.');
        const entry = await readUploadZipImage(zipBlob, descriptor);
        if (!entry) {
            inspected.skipped++;
            inspected.reasons.integrity = Number(inspected.reasons.integrity || 0) + 1;
            continue;
        }
        entries.push(entry);
        retainedBytes += descriptor.uncompressedSize;
    }
    return {...inspected, entries};
}

/** Inspect bounded ZIP metadata and admit ordered image descriptors without retaining image bytes.
 * @param {Blob} zipBlob Immutable user-selected or OPFS-backed archive.
 * @param {{maximumEntries?:number,maximumEntryBytes?:number,maximumTotalBytes?:number,maximumRetainedBytes?:number}} limits Existing per-entry/count/expanded-data limits; retention applies only to the eager wrapper.
 * @returns {Promise<{entries:UploadZipEntry[],skipped:number,totalEntries:number,reasons:Record<string,number>}>} Admitted descriptors and counted skips; malformed/oversized structures reject before image reads.
 */
async function inspectUploadZipEntries(zipBlob, limits) {
    if (!(zipBlob instanceof Blob)) throw new Error('ZIP extraction expected a Blob payload.');
    const maximumEntries = Math.max(1, Math.min(Number(limits.maximumEntries || 10000), 10000));
    const maximumEntryBytes = Math.max(1, Math.min(Number(limits.maximumEntryBytes || uploadZipRetainedBytes), uploadZipRetainedBytes));
    const maximumTotalBytes = Math.max(maximumEntryBytes, Math.min(Number(limits.maximumTotalBytes || 2 * 1024 * 1024 * 1024), 2 * 1024 * 1024 * 1024));
    const end = await findZipEndRecord(zipBlob);
    if (end.entryCount > maximumEntries) throw uploadZipFailure('entries', 'ZIP contains too many entries.');
    const centralBlob = zipBlob.slice(end.centralOffset, end.centralOffset + end.centralSize);
    const centralBytes = new Uint8Array(await centralBlob.arrayBuffer());
    const centralView = new DataView(centralBytes.buffer);
    const entries = [];
    let skipped = 0;
    let totalUncompressed = 0;
    const seenPaths = new Set();
    const reasons = {};
    const skip = reason => { skipped++; reasons[reason] = Number(reasons[reason] || 0) + 1; };
    let offset = 0;
    for (let index = 0; index < end.entryCount; index++) {
        if (offset + 46 > centralBytes.length || centralView.getUint32(offset, true) !== 0x02014b50) throw uploadZipFailure('invalid', 'ZIP central directory is truncated or invalid.');
        const flags = centralView.getUint16(offset + 8, true);
        const method = centralView.getUint16(offset + 10, true);
        const expectedCrc = centralView.getUint32(offset + 16, true);
        const compressedSize = centralView.getUint32(offset + 20, true);
        const uncompressedSize = centralView.getUint32(offset + 24, true);
        const nameLength = centralView.getUint16(offset + 28, true);
        const extraLength = centralView.getUint16(offset + 30, true);
        const commentLength = centralView.getUint16(offset + 32, true);
        const localOffset = centralView.getUint32(offset + 42, true);
        const disk = centralView.getUint16(offset + 34, true);
        const unixMode = centralView.getUint32(offset + 38, true) >>> 16;
        const nextOffset = offset + 46 + nameLength + extraLength + commentLength;
        if (nextOffset > centralBytes.length) throw uploadZipFailure('invalid', 'ZIP central-directory entry boundary is invalid.');
        const path = decodeUploadZipName(centralBytes.slice(offset + 46, offset + 46 + nameLength), (flags & 0x0800) !== 0);
        offset = nextOffset;
        if (disk !== 0) throw uploadZipFailure('invalid', 'Multi-disk ZIP entries are not supported.');
        if ([compressedSize, uncompressedSize, localOffset].includes(0xffffffff)) throw uploadZipFailure('zip64', 'ZIP64 archives are not supported by browser upload yet.');
        if (!safeUploadZipImagePath(path) || (unixMode & 0xf000) === 0xa000) {
            const unsupported = /\.(?:heic|heif|dng|raw|cr2|nef|arw)$/i.test(path) ? 'raw'
                : /\.(?:mp4|mov|m4v)$/i.test(path) ? 'video' : 'other';
            skip(unsupported);
            continue;
        }
        if (seenPaths.has(path)) { skip('duplicate'); continue; }
        seenPaths.add(path);
        if ((flags & 0x0001) !== 0 || ![0, 8].includes(method)) { skip('encoding'); continue; }
        if (uncompressedSize <= 0 || uncompressedSize > maximumEntryBytes) { skip('size'); continue; }
        if (uncompressedSize > compressedSize * 250 + 1024 * 1024) {
            skip('size');
            continue;
        }
        totalUncompressed += uncompressedSize;
        if (totalUncompressed > maximumTotalBytes) throw uploadZipFailure('size', 'ZIP expanded data exceeds the browser extraction safety limit.');
        entries.push({name: uploadZipBasename(path), path, localOffset, compressedSize, uncompressedSize, method, expectedCrc});
    }
    if (offset !== centralBytes.length) throw uploadZipFailure('invalid', 'ZIP central-directory size is inconsistent.');
    return {entries, skipped, totalEntries: end.entryCount, reasons};
}

/** Read and verify one admitted image while leaving every other archive payload on disk.
 * @param {Blob} zipBlob Immutable archive owned by this reader session.
 * @param {UploadZipEntry} entry Descriptor produced by the checked central-directory owner.
 * @returns {Promise<{name:string,path:string,blob:Blob}|null>} Genuine supported image bytes, or null for a CRC/signature skip; malformed extraction rejects.
 */
async function readUploadZipImage(zipBlob, entry) {
    const payload = await extractUploadZipPayload(zipBlob, entry.localOffset, entry.compressedSize, entry.uncompressedSize, entry.method);
    const bytes = new Uint8Array(await payload.arrayBuffer());
    if (crc32(bytes) !== entry.expectedCrc || !detectImageFormatFromSignature(bytes.subarray(0, 16))) return null;
    return {name: entry.name, path: entry.path, blob: new Blob([bytes])};
}

/**
 * Locate the bounded classic single-disk end record, allowing only bounded zero padding and rejecting ZIP64.
 * @param {Blob} zipBlob User-selected disk-backed archive reference.
 * @returns {Promise<{entryCount:number,centralSize:number,centralOffset:number}>} Checked directory bounds; rejects malformed or oversized metadata.
 */
async function findZipEndRecord(zipBlob) {
    const tailLength = Math.min(zipBlob.size, uploadZipTailBytes);
    const tail = new Uint8Array(await zipBlob.slice(zipBlob.size - tailLength).arrayBuffer());
    const view = new DataView(tail.buffer);
    for (let offset = tail.length - 22; offset >= 0; offset--) {
        if (view.getUint32(offset, true) !== 0x06054b50) continue;
        const disk = view.getUint16(offset + 4, true);
        const centralDisk = view.getUint16(offset + 6, true);
        const diskEntries = view.getUint16(offset + 8, true);
        const entryCount = view.getUint16(offset + 10, true);
        const centralSize = view.getUint32(offset + 12, true);
        const centralOffset = view.getUint32(offset + 16, true);
        const recordEnd = offset + 22 + view.getUint16(offset + 20, true);
        const padding = tail.length - recordEnd;
        if (padding < 0 || padding > uploadZipZeroPaddingBytes || tail.subarray(recordEnd).some(byte => byte !== 0)) continue;
        if (disk !== 0 || centralDisk !== 0 || diskEntries !== entryCount) throw uploadZipFailure('invalid', 'Multi-disk ZIP archives are not supported.');
        if (entryCount === 0xffff || centralSize === 0xffffffff || centralOffset === 0xffffffff) throw uploadZipFailure('zip64', 'ZIP64 archives are not supported by browser upload yet.');
        if (centralSize > uploadZipDirectoryBytes) throw uploadZipFailure('directory', 'ZIP directory exceeds the browser metadata limit.');
        if (centralOffset + centralSize !== zipBlob.size - tailLength + offset) throw uploadZipFailure('invalid', 'ZIP central-directory boundary is invalid.');
        return {entryCount, centralSize, centralOffset};
    }
    throw uploadZipFailure('invalid', 'ZIP end record was not found. The archive may be damaged.');
}

/**
 * Decode a ZIP path using UTF-8 or the existing legacy Western fallback.
 * @param {Uint8Array} bytes Encoded directory name.
 * @param {boolean} utf8 Whether the ZIP declares UTF-8.
 * @returns {string} Slash-normalized name, or empty on decoding failure.
 */
function decodeUploadZipName(bytes, utf8) {
    try {
        return new TextDecoder(utf8 ? 'utf-8' : 'windows-1252', {fatal: utf8}).decode(bytes).replace(/\\/g, '/');
    } catch (error) {
        return '';
    }
}

/**
 * Reject absolute/traversing/hidden paths and unsupported image suffixes.
 * @param {string} path Decoded directory path.
 * @returns {boolean} True only for a relative supported image path.
 */
function safeUploadZipImagePath(path) {
    const normalized = String(path || '');
    if (/^(?:\/|[A-Za-z]:)/.test(normalized)) return false;
    if (normalized === '' || normalized.endsWith('/') || normalized.includes('\0')) return false;
    const segments = normalized.split('/');
    if (segments.some((segment) => segment === '' || segment === '.' || segment === '..' || segment.startsWith('.')) || segments[0] === '__MACOSX') return false;
    return /\.(?:jpe?g|png|webp|gif)$/i.test(normalized);
}

/**
 * Return the filename component after the caller checks the complete path.
 * @param {string} path Checked relative archive path.
 * @returns {string} Basename, or a neutral name when absent.
 */
function uploadZipBasename(path) {
    return String(path || '').split('/').pop() || 'archive-image';
}

/**
 * Extract one ZIP payload, stopping inflation before its declared size is exceeded.
 * @param {Blob} zipBlob Archive reference; never read as a complete buffer.
 * @param {number} localOffset Local header byte offset.
 * @param {number} compressedSize Central-directory compressed byte length.
 * @param {number} uncompressedSize Preflighted maximum expanded byte length.
 * @param {number} method Stored (0) or Deflate (8) encoding.
 * @returns {Promise<Blob>} One bounded payload; rejects corruption or unsupported browser inflation.
 */
async function extractUploadZipPayload(zipBlob, localOffset, compressedSize, uncompressedSize, method) {
    const header = new Uint8Array(await zipBlob.slice(localOffset, localOffset + 30).arrayBuffer());
    if (header.length !== 30 || new DataView(header.buffer).getUint32(0, true) !== 0x04034b50) throw uploadZipFailure('invalid', 'ZIP local header is invalid.');
    const view = new DataView(header.buffer);
    const dataStart = localOffset + 30 + view.getUint16(26, true) + view.getUint16(28, true);
    if (view.getUint16(8, true) !== method || (view.getUint16(6, true) & 1) !== 0 || dataStart + compressedSize > zipBlob.size) throw uploadZipFailure('invalid', 'ZIP entry payload boundary or encoding is invalid.');
    const compressed = zipBlob.slice(dataStart, dataStart + compressedSize);
    let payload = compressed;
    if (method === 8) {
        if (!self.DecompressionStream) throw uploadZipFailure('deflate', 'This browser cannot decompress Deflate ZIP entries.');
        let inflater;
        try { inflater = new DecompressionStream('deflate-raw'); }
        catch (error) { throw uploadZipFailure('deflate', 'This browser cannot decompress Deflate ZIP entries.'); }
        const reader = compressed.stream().pipeThrough(inflater).getReader();
        const chunks = [];
        let size = 0;
        try {
            while (true) {
                const chunk = await reader.read();
                if (chunk.done) break;
                size += chunk.value.byteLength;
                if (size > uncompressedSize) throw uploadZipFailure('size', 'ZIP inflation exceeded its declared size.');
                chunks.push(chunk.value);
            }
            payload = new Blob(chunks);
        } finally {
            await reader.cancel().catch(() => {});
            reader.releaseLock();
        }
    }
    if (payload.size !== uncompressedSize) throw uploadZipFailure('invalid', 'ZIP entry expanded to an unexpected size.');
    return payload;
}

/**
 * Attach a non-sensitive error category for the coordinator's localized message.
 * @param {string} code Stable resource/format category, never a filename or URL.
 * @param {string} detail Technical description for deterministic worker fixtures.
 * @returns {Error & {code:string}} Classified worker failure without source secrets.
 */
function uploadZipFailure(code, detail) {
    const error = new Error(detail);
    error.code = code;
    return error;
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
    if (self.__browserUploadCrcTable) {
        return self.__browserUploadCrcTable;
    }
    const table = new Uint32Array(256);
    for (let value = 0; value < 256; value++) {
        let crc = value;
        for (let bit = 0; bit < 8; bit++) {
            crc = crc & 1 ? 0xedb88320 ^ (crc >>> 1) : crc >>> 1;
        }
        table[value] = crc >>> 0;
    }
    self.__browserUploadCrcTable = table;
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

