/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_upload_thumbnail_test.mjs
 * Module Type: Regression Test
 * Purpose: Verify header admission and cancellation of the bounded upload preview decoder.
 * Responsibilities:
 *   - Reject oversized, animated and unknown sources before decoding
 *   - Prove serialized work, stale-result disposal and canvas/bitmap release
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
const source = await readFile(new URL('../public/assets/gallery-modules/admin-upload-thumbnail.js', import.meta.url), 'utf8');
const {inspectGalleryUploadHeader: inspect, requestGalleryUploadThumbnail: request, galleryUploadPreviewPolicy: policy}
    = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64');
/**
 * Change PNG header dimensions without invoking a decoder or allocating their pixel data.
 * @param {number} width Declared width in pixels.
 * @param {number} height Declared height in pixels.
 * @return {Buffer} Header fixture; CRC intentionally remains irrelevant to the admission parser.
 */
function pngHeader(width, height) {
    const bytes = Buffer.from(png);
    bytes.writeUInt32BE(width, 16); bytes.writeUInt32BE(height, 20);
    return bytes;
}
/**
 * Build a minimal JPEG header containing one frame and a scan marker.
 * @param {number} width Declared width in pixels.
 * @param {number} height Declared height in pixels.
 * @param {number} marker Supported baseline/progressive or rejected frame marker.
 * @return {Buffer} Header-only JPEG fixture, not an uploadable encoded image.
 */
function jpegHeader(width, height, marker = 0xc0) {
    const bytes = Buffer.from([255, 216, 255, marker, 0, 11, 8, 0, 0, 0, 0, 1, 1, 17, 0, 255, 218]);
    bytes.writeUInt16BE(height, 7); bytes.writeUInt16BE(width, 9);
    return bytes;
}
/**
 * Build one bounded lossless WebP header.
 * @param {number} width Declared width in pixels.
 * @param {number} height Declared height in pixels.
 * @return {Buffer} Header-only WebP container.
 */
function webpHeader(width, height) {
    const bytes = Buffer.alloc(26);
    bytes.write('RIFF'); bytes.writeUInt32LE(18, 4); bytes.write('WEBP', 8);
    bytes.write('VP8L', 12); bytes.writeUInt32LE(5, 16); bytes[20] = 0x2f;
    bytes.writeUInt32LE((width - 1) | ((height - 1) << 14), 21);
    return bytes;
}
assert.deepEqual(inspect(png), {width: 1, height: 1});
assert.deepEqual(inspect(pngHeader(4096, 4096)), {width: 4096, height: 4096});
for (const bytes of [pngHeader(0, 1), pngHeader(1, 0), pngHeader(8193, 1), pngHeader(5000, 5000), pngHeader(0xffffffff, 1)]) {
    assert.equal(inspect(bytes), null, 'Reject invalid/pixel/edge geometry before decoding');
}
assert.deepEqual(inspect(jpegHeader(200, 100)), {width: 200, height: 100});
assert.deepEqual(inspect(jpegHeader(100, 200, 0xc2)), {width: 100, height: 200});
assert.equal(inspect(jpegHeader(9000, 1)), null);
assert.equal(inspect(jpegHeader(200, 100, 0xc3)), null, 'Unsupported JPEG frame uses icon');
assert.deepEqual(inspect(webpHeader(200, 100)), {width: 200, height: 100});
assert.equal(inspect(webpHeader(8193, 1)), null);
const animatedPngChunk = Buffer.alloc(20); animatedPngChunk.writeUInt32BE(8); animatedPngChunk.write('acTL', 4);
assert.equal(inspect(Buffer.concat([png.subarray(0, 33), animatedPngChunk, png.subarray(33)])), null, 'APNG cannot retain animated decoder surfaces');
const vp8x = Buffer.alloc(18); vp8x.write('VP8X'); vp8x.writeUInt32LE(10, 4); vp8x[12] = 199; vp8x[15] = 99;
assert.deepEqual(inspect(Buffer.concat([webpHeader(200, 100).subarray(0, 12), vp8x, webpHeader(200, 100).subarray(12)])), {width: 200, height: 100});
vp8x[8] = 2;
assert.equal(inspect(Buffer.concat([webpHeader(200, 100).subarray(0, 12), vp8x, webpHeader(200, 100).subarray(12)])), null, 'Animated WebP is a safe icon');
for (const bytes of [Buffer.alloc(0), Buffer.from('GIF89a'), Buffer.from('<svg/>'), png.subarray(0, 20), jpegHeader(2, 2).subarray(0, 10)]) {
    assert.equal(inspect(bytes), null, 'Unknown or truncated header is not decoded');
}
const metadata = Buffer.alloc(65537); metadata[0] = 255; metadata[1] = 225; metadata.writeUInt16BE(65535, 2);
assert.equal(inspect(Buffer.concat([Buffer.from([255, 216]), metadata, metadata, metadata, metadata, jpegHeader(2, 2).subarray(2)])), null, 'Probe never scans beyond its fixed prefix');

const image = new File([png], 'original.png', {type: 'image/png', lastModified: 123});
let decodeCalls = 0, closed = 0, active = 0, maximumActive = 0;
let gate = null, rejectDecode = false, rejectCanvas = false;
const canvases = [];
globalThis.createImageBitmap = async file => {
    assert.equal(file, image, 'Decode original File, not a copied base64/string source');
    decodeCalls++; active++; maximumActive = Math.max(maximumActive, active);
    if (gate) await gate;
    if (rejectDecode) { active--; throw new Error('Unreadable image'); }
    return {width: 1600, height: 800, close: () => { closed++; active--; }};
};
globalThis.document = {createElement: tag => {
    assert.equal(tag, 'canvas');
    const canvas = {width: 0, height: 0, getContext: () => ({drawImage: () => {}}), toBlob: callback => {
        assert.equal(canvas.width, 256); assert.equal(canvas.height, 128);
        callback(rejectCanvas ? null : new Blob(['small PNG'], {type: 'image/png'}));
    }};
    canvases.push(canvas); return canvas;
}};
const result = await request(image, new AbortController().signal);
assert.equal(result.type, 'image/png'); assert.equal(await result.text(), 'small PNG');
assert.equal(image.name, 'original.png'); assert.equal(image.lastModified, 123); assert.deepEqual(Buffer.from(await image.arrayBuffer()), png);
assert.equal(closed, 1); assert.ok(canvases.every(canvas => canvas.width === 0 && canvas.height === 0));
const beforeRejected = decodeCalls;
for (const file of [new File([], 'empty.png'), new File([pngHeader(10000, 1)], 'wide.png'), new File(['unknown'], 'unknown.png')]) {
    assert.equal(await request(file, new AbortController().signal), null);
}
const oversized = new File([new Uint8Array(policy.maxSourceBytes + 1)], 'large.png');
assert.equal(await request(oversized, new AbortController().signal), null);
assert.equal(decodeCalls, beforeRejected, 'Rejected headers and byte limits never invoke the decoder');

let release;
gate = new Promise(resolve => { release = resolve; });
const first = new AbortController(), second = new AbortController(), third = new AbortController();
const firstResult = request(image, first.signal);
await new Promise(resolve => setImmediate(resolve));
assert.equal(active, 1);
const secondResult = request(image, second.signal);
const thirdResult = request(image, third.signal);
second.abort(); first.abort();
assert.equal(await firstResult, null); assert.equal(await secondResult, null);
assert.equal(active, 1, 'Cancellation does not release an in-progress native decode slot early');
gate = null; release();
assert.equal((await thirdResult).type, 'image/png');
assert.equal(maximumActive, 1, 'New generations never overlap a cancelled native decoder');
assert.equal(active, 0); assert.equal(closed, decodeCalls);
assert.ok(canvases.every(canvas => canvas.width === 0 && canvas.height === 0));

rejectDecode = true;
assert.equal(await request(image, new AbortController().signal), null);
rejectDecode = false; rejectCanvas = true;
assert.equal(await request(image, new AbortController().signal), null);
rejectCanvas = false;
assert.equal((await request(image, new AbortController().signal)).type, 'image/png', 'Queue recovers after decoder/encoder errors');
const decoder = globalThis.createImageBitmap;
delete globalThis.createImageBitmap;
assert.equal(await request(image, new AbortController().signal), null, 'Older/restricted browser keeps its original upload and icon');
globalThis.createImageBitmap = decoder;

// Hold one decode to prove both the waiting bound and eager queued cancellation.
gate = new Promise(resolve => { release = resolve; });
const controllers = Array.from({length: 40}, () => new AbortController());
const requests = controllers.map(controller => request(image, controller.signal));
await new Promise(resolve => setImmediate(resolve));
assert.ok((await Promise.all(requests.slice(13))).every(value => value === null), 'Excess requests fall back instead of building a backlog');
controllers.forEach(controller => controller.abort());
assert.ok((await Promise.all(requests)).every(value => value === null));
gate = null; release();
await new Promise(resolve => setImmediate(resolve));
assert.equal(active, 0); assert.equal(maximumActive, 1);
console.log('admin_upload_thumbnail_test: OK (header bounds, serial decode, cancellation, resource disposal)');
