/** Integration test for browser-worker extraction of stored and Deflate user ZIPs. */

import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';
import {deflateRawSync} from 'node:zlib';

/** Return an unsigned CRC32 for fixture bytes. */
function fixtureCrc32(bytes) {
    let crc = -1;
    for (const byte of bytes) {
        crc ^= byte;
        for (let bit = 0; bit < 8; bit++) crc = crc & 1 ? 0xedb88320 ^ (crc >>> 1) : crc >>> 1;
    }
    return (crc ^ -1) >>> 0;
}

/** Build a classic single-disk ZIP fixture with central-directory metadata. */
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

/** Execute one worker message and resolve its posted result. */
async function executeWorkerExtraction(zipBlob) {
    let messageHandler;
    let resolveResult;
    const resultPromise = new Promise((resolve) => { resolveResult = resolve; });
    const workerGlobal = {
        addEventListener(type, handler) { if (type === 'message') messageHandler = handler; },
        postMessage(message) { resolveResult(message); },
        DecompressionStream,
    };
    const context = vm.createContext({self: workerGlobal, Blob, Response, DecompressionStream, TextDecoder, TextEncoder, DataView, Uint8Array, ArrayBuffer, File, console});
    vm.runInContext(await readFile(new URL('../public/assets/gallery-modules/browser-image-worker.js', import.meta.url), 'utf8'), context);
    messageHandler({data: {action: 'extractUploadZip', id: 7, zipBlob, limits: {maximumEntries: 100, maximumEntryBytes: 1024 * 1024, maximumTotalBytes: 4 * 1024 * 1024}}});
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
console.log('Browser upload ZIP worker tests passed.');
