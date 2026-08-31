import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {ZipStreamWriter} from '../public/assets/gallery-modules/zip-stream-writer.js';

const output = process.argv[2];
if (!output) throw new Error('Output ZIP path required.');

function emptyReadable() {
    return new ReadableStream({start(controller) { controller.close(); }});
}

const handle = await fs.open(output, 'w');
try {
    const writer = new ZipStreamWriter({write: (bytes) => handle.write(bytes)});
    for (let index = 0; index < 65535; index += 1) {
        await writer.addReadable(`empty-${String(index).padStart(5, '0')}.txt`, 0, emptyReadable());
    }
    await writer.finalize();
} finally {
    await handle.close();
}

const archive = await fs.readFile(output);
assert.notEqual(archive.indexOf(Buffer.from('504b0606', 'hex')), -1, 'ZIP64 EOCD record must be present at the classic entry-count boundary.');
assert.notEqual(archive.indexOf(Buffer.from('504b0607', 'hex')), -1, 'ZIP64 EOCD locator must be present.');
console.log('gallery_download_zip64_test: ok');
