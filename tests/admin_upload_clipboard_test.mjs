/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_upload_clipboard_test.mjs
 * Module Type: Regression Test
 * Purpose: Verify clipboard MIME filtering, filenames and multi-image extraction.
 * Responsibilities: Exercise production selection parsing without gallery storage.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
const source = await readFile(new URL('../public/assets/gallery-modules/admin-upload-selection.js', import.meta.url), 'utf8');
const {clipboardUploadFiles} = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
const input = {accept: '.jpg,.jpeg,.png,.gif,.webp,image/*'};
const named = new File(['jpeg'], 'holiday.jpeg', {type: 'image/jpeg', lastModified: 123});
const screenshot = new File(['png'], '', {type: 'image/png', lastModified: 456});
const blob = new File(['webp'], 'blob', {type: 'image/webp'});
const items = [named, screenshot, blob].map(file => ({kind: 'file', type: file.type, getAsFile: () => file}));
items.push({kind: 'string', type: 'text/html', getAsFile: () => {throw new Error('Text must not be read');}});
const files = clipboardUploadFiles({items, files: [named]}, input);
assert.equal(files.length, 3, 'Do not double-add items also exposed in files');
assert.equal(files[0], named, 'Preserve original names and File references');
assert.match(files[1].name, /^clipboard-\d{8}T\d{9}Z-\d+\.png$/);
assert.match(files[2].name, /^clipboard-\d{8}T\d{9}Z-\d+\.webp$/);
assert.equal(files[1].lastModified, 456);
assert.equal(await files[1].text(), 'png');
assert.notEqual(clipboardUploadFiles({files: [screenshot]}, input)[0].name, files[1].name);
assert.deepEqual(clipboardUploadFiles(null, input), []);
assert.deepEqual(clipboardUploadFiles({files: [named]}, input), [named]);
for (const type of ['text/plain', 'application/pdf', 'image/svg+xml', 'image/bmp', 'image/tiff', '']) {
    assert.deepEqual(clipboardUploadFiles({files: [new File(['unsupported'], 'payload.png', {type})]}, input), [], type);
}
assert.deepEqual(clipboardUploadFiles({files: [new File([], 'empty.png', {type: 'image/png'})]}, input), []);
assert.deepEqual(clipboardUploadFiles({items: [{kind: 'file', type: 'image/png', getAsFile: () => null}]}, input), []);
assert.deepEqual(clipboardUploadFiles({files: [named]}, {accept: 'image/png,.png'}), []);
for (const [type, extension] of [['image/heic', 'heic'], ['image/heif', 'heif'], ['image/x-adobe-dng', 'dng']]) {
    const file = new File(['optional'], '', {type});
    assert.deepEqual(clipboardUploadFiles({files: [file]}, input), [], 'Optional formats require explicit server hints');
    assert.equal(clipboardUploadFiles({files: [file]}, {accept: input.accept + ',.' + extension}).length, 1);
}
console.log('admin_upload_clipboard_test: OK');
