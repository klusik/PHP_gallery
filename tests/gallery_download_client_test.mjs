import assert from 'node:assert/strict';
import {
    fetchGalleryDownloadEntry,
    galleryDownloadMemoryPolicy,
    validGalleryDownloadManifest,
} from '../public/assets/gallery-modules/gallery-download.js';

const valid = {
    ok: true,
    filename: 'gallery.zip',
    files: [
        {name: 'a.jpg', size: 4, url: '/a'},
        {name: 'b.jpg', size: 6, url: '/b'},
    ],
    total_files: 2,
    total_bytes: 10,
    memory_fallback_warning_bytes: 20,
    memory_fallback_max_bytes: 40,
};
assert.equal(validGalleryDownloadManifest(valid), true);
assert.equal(validGalleryDownloadManifest({...valid, total_bytes: 11}), false);
assert.equal(validGalleryDownloadManifest({...valid, files: [{name: '../x', size: 10, url: '/x'}], total_files: 1}), false);
assert.equal(validGalleryDownloadManifest({...valid, files: [{name: 'x', size: -1, url: '/x'}], total_files: 1, total_bytes: -1}), false);

assert.equal(galleryDownloadMemoryPolicy(valid, true).allowed, true);
assert.equal(galleryDownloadMemoryPolicy(valid, false).warning, true, 'ZIP overhead should be included in the fallback estimate.');
assert.equal(galleryDownloadMemoryPolicy({...valid, memory_fallback_max_bytes: 4096}, false).allowed, true);
assert.equal(galleryDownloadMemoryPolicy({...valid, total_bytes: 4096, memory_fallback_max_bytes: 4096}, false).allowed, false);

const goodResponse = new Response(new Uint8Array([1, 2, 3, 4]), {status: 200, headers: {'Content-Length': '4'}});
assert.equal(await fetchGalleryDownloadEntry({name: 'a.jpg', size: 4, url: '/a'}, new AbortController().signal, async () => goodResponse), goodResponse);
await assert.rejects(
    fetchGalleryDownloadEntry({name: 'a.jpg', size: 4, url: '/a'}, new AbortController().signal, async () => new Response('failure', {status: 500})),
    /a\.jpg/
);
await assert.rejects(
    fetchGalleryDownloadEntry({name: 'a.jpg', size: 4, url: '/a'}, new AbortController().signal, async () => new Response(new Uint8Array([1, 2]), {status: 200, headers: {'Content-Length': '2'}})),
    /a\.jpg/
);

const abortController = new AbortController();
const pending = fetchGalleryDownloadEntry(
    {name: 'a.jpg', size: 4, url: '/a'},
    abortController.signal,
    async (_url, options) => new Promise((resolve, reject) => {
        options.signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')), {once: true});
    })
);
abortController.abort();
await assert.rejects(pending, (error) => error?.name === 'AbortError');

console.log('gallery_download_client_test: ok');
