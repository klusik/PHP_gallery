import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import crypto from 'node:crypto';
import {ZipStreamWriter, crc32ForBytes, zip64RequiredForArchive} from '../public/assets/gallery-modules/zip-stream-writer.js';

const output = process.argv[2];
if (!output) throw new Error('Output ZIP path required.');

class FileSink {
    constructor(handle) { this.handle = handle; }
    async write(bytes) { await this.handle.write(bytes); }
}

function readable(bytes, chunkSize = 3) {
    let offset = 0;
    return new ReadableStream({
        pull(controller) {
            if (offset >= bytes.length) {
                controller.close();
                return;
            }
            const end = Math.min(bytes.length, offset + chunkSize);
            controller.enqueue(bytes.slice(offset, end));
            offset = end;
        },
    });
}

const fixtures = [
    ['folder/hello.txt', new TextEncoder().encode('hello gallery')],
    ['folder/žluťoučký.txt', new TextEncoder().encode('UTF-8 filename')],
    ['folder/duplicate.txt', new Uint8Array([0, 1, 2, 3, 4, 5])],
    ['folder/duplicate-2.txt', new Uint8Array([9, 8, 7, 6])],
];

const handle = await fs.open(output, 'w');
try {
    const writer = new ZipStreamWriter(new FileSink(handle));
    let progress = 0;
    const progressValues = [];
    for (const [name, bytes] of fixtures) {
        await writer.addReadable(name, bytes.byteLength, readable(bytes), (count) => {
            progress += count;
            progressValues.push(progress);
        });
    }
    await writer.finalize();
    const expectedProgress = fixtures.reduce((sum, [, bytes]) => sum + bytes.length, 0);
    assert.equal(progress, expectedProgress);
    assert.equal(progressValues.every((value, index) => value > (progressValues[index - 1] ?? 0) && value <= expectedProgress), true);
} finally {
    await handle.close();
}

assert.equal(crc32ForBytes(new TextEncoder().encode('123456789')), 0xcbf43926);
assert.equal(zip64RequiredForArchive(10, 1000, 500, []), false);
assert.equal(zip64RequiredForArchive(65535, 1000, 500, []), true);
assert.equal(zip64RequiredForArchive(1, 0xffffffffn, 500, []), true);
assert.equal(zip64RequiredForArchive(1, 1000, 0xffffffffn, []), true);
assert.equal(zip64RequiredForArchive(1, 1000, 500, [{size: 0xffffffffn, offset: 0n}]), true);

const hashes = Object.fromEntries(fixtures.map(([name, bytes]) => [name, crypto.createHash('sha256').update(bytes).digest('hex')]));
console.log(JSON.stringify({ok: true, hashes}));

const mismatchWriter = new ZipStreamWriter({write: async () => {}});
await assert.rejects(
    mismatchWriter.addReadable('short.bin', 5, readable(new Uint8Array([1, 2, 3, 4]))),
    /length did not match/
);

let sinkWrites = 0;
const failingSinkWriter = new ZipStreamWriter({
    write: async () => {
        sinkWrites += 1;
        if (sinkWrites === 2) throw new Error('synthetic sink failure');
    },
});
await assert.rejects(
    failingSinkWriter.addReadable('sink.bin', 4, readable(new Uint8Array([1, 2, 3, 4]), 4)),
    /synthetic sink failure/
);

function generatedReadable(totalBytes, chunkSize) {
    let remaining = totalBytes;
    return new ReadableStream({
        pull(controller) {
            if (remaining === 0) {
                controller.close();
                return;
            }
            const size = Math.min(chunkSize, remaining);
            controller.enqueue(new Uint8Array(size));
            remaining -= size;
        },
    });
}

let maxSinkWrite = 0;
let streamedBytes = 0;
const boundedWriter = new ZipStreamWriter({
    write: async (bytes) => {
        maxSinkWrite = Math.max(maxSinkWrite, bytes.byteLength);
        streamedBytes += bytes.byteLength;
    },
});
const boundedPayloadBytes = 8 * 1024 * 1024;
await boundedWriter.addReadable('bounded.bin', boundedPayloadBytes, generatedReadable(boundedPayloadBytes, 64 * 1024));
await boundedWriter.finalize();
assert.equal(maxSinkWrite <= 64 * 1024, true, 'Streaming writer should write at most the current source chunk, not the whole payload.');
assert.equal('data' in boundedWriter.entries[0] || 'bytes' in boundedWriter.entries[0], false, 'Central-directory metadata must not retain file payloads.');
assert.equal(streamedBytes > boundedPayloadBytes, true, 'ZIP framing should be emitted around the streamed payload.');
