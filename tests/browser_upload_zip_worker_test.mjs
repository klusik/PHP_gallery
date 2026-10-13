/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Exercise stored and Deflate ZIP extraction in the browser worker.
 * Responsibilities:
 *   - Verify extracted bytes and failure handling for synthetic archive inputs.
 *   - Run mandatory assisted-iCloud source and uploader scenarios within this registered owner.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/browser_upload_zip_worker_test.mjs
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */
/** Integration test for browser-worker extraction of stored and Deflate user ZIPs. */

import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';
import {deflateRawSync} from 'node:zlib';

/**
 * Return an unsigned CRC32 for deterministic fixture bytes.
 * @param {Uint8Array} bytes Synthetic uncompressed data.
 * @returns {number} Unsigned ZIP CRC32.
 */
function fixtureCrc32(bytes) {
    let crc = -1;
    for (const byte of bytes) {
        crc ^= byte;
        for (let bit = 0; bit < 8; bit++) crc = crc & 1 ? 0xedb88320 ^ (crc >>> 1) : crc >>> 1;
    }
    return (crc ^ -1) >>> 0;
}

/**
 * Build a classic single-disk ZIP with controllable integrity/encoding metadata.
 * @param {Array<{name:string,bytes:Uint8Array,method:number,badCrc?:boolean,extraFlags?:number}>} entries Synthetic entries in directory order.
 * @returns {Blob} Complete small fixture archive; never contains real user photos.
 */
function buildFixtureZip(entries) {
    const localParts = [];
    const centralParts = [];
    let localOffset = 0;
    for (const entry of entries) {
        const name = Buffer.from(entry.name, 'utf8');
        const source = Buffer.from(entry.bytes);
        const compressed = entry.method === 8 ? deflateRawSync(source) : source;
        const flags = 0x0800 | Number(entry.extraFlags || 0);
        const crc = entry.badCrc ? 1 : fixtureCrc32(source);
        const local = Buffer.alloc(30 + name.length);
        local.writeUInt32LE(0x04034b50, 0); local.writeUInt16LE(20, 4); local.writeUInt16LE(flags, 6); local.writeUInt16LE(entry.method, 8);
        local.writeUInt32LE(crc, 14); local.writeUInt32LE(compressed.length, 18); local.writeUInt32LE(source.length, 22); local.writeUInt16LE(name.length, 26); name.copy(local, 30);
        localParts.push(local, compressed);
        const central = Buffer.alloc(46 + name.length);
        central.writeUInt32LE(0x02014b50, 0); central.writeUInt16LE(20, 4); central.writeUInt16LE(20, 6); central.writeUInt16LE(flags, 8); central.writeUInt16LE(entry.method, 10);
        central.writeUInt32LE(crc, 16); central.writeUInt32LE(compressed.length, 20); central.writeUInt32LE(source.length, 24); central.writeUInt16LE(name.length, 28); central.writeUInt32LE(localOffset, 42); name.copy(central, 46);
        centralParts.push(central);
        localOffset += local.length + compressed.length;
    }
    const central = Buffer.concat(centralParts);
    const end = Buffer.alloc(22);
    end.writeUInt32LE(0x06054b50, 0); end.writeUInt16LE(entries.length, 8); end.writeUInt16LE(entries.length, 10); end.writeUInt32LE(central.length, 12); end.writeUInt32LE(localOffset, 16);
    return new Blob([...localParts, central, end], {type: 'application/zip'});
}

/**
 * Execute the real worker with synthetic archive bytes and configurable limits.
 * @param {Blob} zipBlob Synthetic archive or sparse metadata-backed fixture.
 * @param {{maximumEntries?:number,maximumEntryBytes?:number,maximumTotalBytes?:number,maximumRetainedBytes?:number}} limits Optional extraction bounds for boundary tests.
 * @returns {Promise<{ok:boolean,code?:string,error?:string,result?:{entries:Array<{name:string,path:string,blob:Blob}>,skipped:number,totalEntries:number,reasons:Record<string,number>}}>} Posted worker success or classified failure.
 */
async function executeWorkerExtraction(zipBlob, limits = {}) {
    let messageHandler;
    let resolveResult;
    const resultPromise = new Promise((resolve) => { resolveResult = resolve; });
    const workerGlobal = {
        /**
         * Capture the worker's message receiver in the isolated fixture scope.
         * @param {string} type Requested event type.
         * @param {function({data:{action:string,id:number,zipBlob:Blob,limits:Record<string,number>}}):void} handler Worker receiver callback.
         * @returns {void} Retains only a message event callback.
         */
        addEventListener(type, handler) { if (type === 'message') messageHandler = handler; },
        /**
         * Resolve the synthetic invocation with the worker's actual envelope.
         * @param {{ok:boolean,id:number,code?:string,error?:string,result?:{entries:Array<{name:string,path:string,blob:Blob}>,skipped:number,totalEntries:number,reasons:Record<string,number>}}} message Worker result.
         * @returns {void} Resolves the fixture promise.
         */
        postMessage(message) { resolveResult(message); },
        DecompressionStream,
    };
    const context = vm.createContext({self: workerGlobal, Blob, Response, DecompressionStream, TextDecoder, TextEncoder, DataView, Uint8Array, ArrayBuffer, File, console});
    vm.runInContext(await readFile(new URL('../public/assets/gallery-modules/browser-image-worker.js', import.meta.url), 'utf8'), context);
    messageHandler({data: {action: 'extractUploadZip', id: 7, zipBlob, limits: {maximumEntries: 100, maximumEntryBytes: 1024 * 1024, maximumTotalBytes: 4 * 1024 * 1024, ...limits}}});
    return resultPromise;
}

const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
const fixture = buildFixtureZip([
    {name: 'Photos/stored.png', bytes: png, method: 0},
    {name: 'Photos/deflated.jpg', bytes: png, method: 8},
    {name: 'Photos/notes.txt', bytes: Buffer.from('skip me'), method: 8},
    {name: '../escape.png', bytes: png, method: 0},
    {name: '__MACOSX/._stored.png', bytes: png, method: 0},
    {name: 'Photos/corrupt.png', bytes: png, method: 8, badCrc: true},
    {name: 'Photos/encrypted.png', bytes: png, method: 0, extraFlags: 1},
]);
const result = await executeWorkerExtraction(fixture);
assert.equal(result.ok, true);
assert.deepEqual(Array.from(result.result.entries, (entry) => entry.name), ['stored.png', 'deflated.jpg']);
assert.equal(result.result.skipped, 5);
assert.equal(result.result.totalEntries, 7);
assert.equal(result.result.reasons.encoding, 1);
assert.equal(result.result.reasons.integrity, 1);
assert.equal(result.result.reasons.other, 3);

const unsupported = await executeWorkerExtraction(buildFixtureZip([
    {name: 'a.heic', bytes: png, method: 0}, {name: 'b.dng', bytes: png, method: 0},
    {name: 'c.mp4', bytes: png, method: 0}, {name: 'd.mov', bytes: png, method: 0},
    {name: 'a.xmp', bytes: png, method: 0}, {name: '/absolute.png', bytes: png, method: 0},
    {name: 'C:/absolute.png', bytes: png, method: 0}, {name: 'a.png', bytes: png, method: 0},
    {name: 'a.png', bytes: png, method: 0}, {name: 'nested/a.png', bytes: png, method: 0},
]));
assert.deepEqual(Array.from(unsupported.result.entries, entry => entry.path), ['a.png', 'nested/a.png']);
assert.equal(unsupported.result.reasons.raw, 2);
assert.equal(unsupported.result.reasons.video, 2);
assert.equal(unsupported.result.reasons.duplicate, 1);
assert.equal(unsupported.result.skipped, 8);

const retention = await executeWorkerExtraction(buildFixtureZip([
    {name: 'a.png', bytes: png, method: 0}, {name: 'b.png', bytes: png, method: 8},
]), {maximumRetainedBytes: png.length});
assert.equal(retention.ok, false);
assert.equal(retention.code, 'retention', 'Admission fails before retaining a second image above budget');
const small = buildFixtureZip([{name: 'a.png', bytes: png, method: 0}]);
const zip64 = Buffer.from(await small.arrayBuffer());
zip64.writeUInt16LE(0xffff, zip64.length - 14);
zip64.writeUInt16LE(0xffff, zip64.length - 12);
assert.equal((await executeWorkerExtraction(new Blob([zip64]))).code, 'zip64');
const invalid = Buffer.from(await small.arrayBuffer());
invalid.writeUInt32LE(0, 0);
assert.equal((await executeWorkerExtraction(new Blob([invalid]))).ok, false);
assert.equal((await executeWorkerExtraction(new Blob(['not ZIP']))).code, 'invalid');
const bomb = Buffer.from(await buildFixtureZip([{name: 'bomb.png', bytes: Buffer.concat([png, Buffer.alloc(100000)]), method: 8}]).arrayBuffer());
const bombCentral = bomb.readUInt32LE(bomb.length - 6);
bomb.writeUInt32LE(png.length, bombCentral + 24);
const inflated = await executeWorkerExtraction(new Blob([bomb]));
assert.equal(inflated.ok, false);
assert.equal(inflated.code, 'size', 'Actual inflation cannot exceed the admitted declaration');
const directory = Buffer.from(await small.arrayBuffer());
directory.writeUInt32LE(4 * 1024 * 1024 + 1, directory.length - 10);
assert.equal((await executeWorkerExtraction(new Blob([directory]))).code, 'directory');
assert.equal((await executeWorkerExtraction(fixture, {maximumEntries: 1})).code, 'entries');

/**
 * Track reads against a sparse disk-like archive without allocating its large holes.
 * This checks bounded source access, not real browser/process heap consumption.
 */
class SparseArchive extends Blob {
    /**
     * Place a classic synthetic archive directory at the end of a virtual source.
     * @param {Uint8Array} original Small valid archive.
     * @param {number} length Virtual archive byte length.
     * @returns {void} Constructs only bounded header/payload/tail metadata.
     */
    constructor(original, length) {
        super([]);
        this.original = Buffer.from(original);
        this.length = length;
        this.central = this.original.readUInt32LE(this.original.length - 6);
        this.tail = Buffer.from(this.original.subarray(this.central));
        this.tail.writeUInt32LE(length - this.tail.length, this.tail.length - 6);
        this.reads = [];
    }
    /**
     * Expose the virtual disk source size without allocating its holes.
     * @returns {number} Virtual byte length.
     */
    get size() { return this.length; }
    /**
     * Serve only the requested bounded range from sparse fixture metadata.
     * @param {number} start Inclusive byte offset, defaulting to zero like Blob.slice.
     * @param {number} end Exclusive byte offset, defaulting to source size like Blob.slice.
     * @returns {Blob} Small range containing original data or the relocated directory.
     */
    slice(start = 0, end = this.size) {
        this.reads.push(end - start);
        const bytes = Buffer.alloc(end - start);
        for (let i = start; i < end; i++) {
            if (i < this.central) bytes[i - start] = this.original[i];
            if (i >= this.length - this.tail.length) bytes[i - start] = this.tail[i - this.length + this.tail.length];
        }
        return new Blob([bytes]);
    }
    /**
     * Refuse whole-source reads to expose a regression in archive access.
     * @returns {Promise<ArrayBuffer>} Always rejects; full-archive buffering is forbidden.
     */
    async arrayBuffer() { throw new Error('Whole source archive read is forbidden.'); }
}
for (const length of [100 * 1024 * 1024, 1024 * 1024 * 1024, 3 * 1024 * 1024 * 1024]) {
    const sparse = new SparseArchive(new Uint8Array(await small.arrayBuffer()), length);
    const extracted = await executeWorkerExtraction(sparse);
    assert.equal(extracted.ok, true, extracted.error);
    assert.equal(extracted.result.entries.length, 1);
    assert.ok(Math.max(...sparse.reads) <= 65557, 'Sparse large archive reads remain bounded by ZIP tail metadata');
}
// Keep feature scenarios under the inherited central owner: integration pins
// BASIC evaluator/registry bytes to develop, while awaited imports propagate
// every scenario failure and preserve their isolated production-code fixtures.
await import('./browser_upload_zip_worker/icloud_source.mjs');
await import('./browser_upload_zip_worker/icloud_upload.mjs');
console.log('Browser upload ZIP worker and assisted iCloud scenarios passed.');
