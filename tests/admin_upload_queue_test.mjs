/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_upload_queue_test.mjs
 * Module Type: Regression Test
 * Purpose: Verify multi-source local upload queue state and File reference ownership.
 * Responsibilities: Exercise append, replay, reorder, remove and per-form isolation.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
const source = await readFile(new URL('../public/assets/gallery-modules/admin-upload-queue.js', import.meta.url), 'utf8');
const {createGalleryUploadQueue} = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
const image = name => new File([name], name, {type: 'image/png'});
const a = image('A.png'), b = image('B.png'), c = image('C.png');
const queue = createGalleryUploadQueue([a]);
const original = queue.items()[0];
queue.append([b], 'paste');
queue.append([c], 'picker');
queue.append([b], 'drop');
assert.deepEqual(queue.items().map(item => item.file), [a, b, c, b]);
assert.deepEqual(queue.items().map(item => item.source), ['initial', 'paste', 'picker', 'drop']);
const ids = queue.items().map(item => item.id);
assert.equal(new Set(ids).size, 4, 'Repeated File occurrences are deliberate and distinct');
assert.ok(queue.move(ids[3], -1));
assert.ok(!queue.move(ids[0], -1));
assert.deepEqual(queue.items().map(item => item.file), [a, b, b, c]);
assert.ok(queue.remove(ids[1]) && !queue.remove(ids[1]));
assert.deepEqual(queue.items().map(item => item.file), [a, b, c]);
const stable = queue.items().map(item => item.id);
queue.reconcile([a, b, c, image('D.png')]);
assert.deepEqual(queue.items().slice(0, 3).map(item => item.id), stable);
assert.equal(queue.items()[3].source, 'external');
queue.reconcile([a, c]);
assert.deepEqual(queue.items().map(item => item.id), [stable[0], stable[2]]);
queue.clear();
assert.equal(queue.items().length, 0);
queue.append([a], 'paste');
assert.notEqual(queue.items()[0].id, original.id);
const other = createGalleryUploadQueue([a]);
assert.notEqual(other.items()[0].id, queue.items()[0].id);
assert.equal(queue.items().length, 1);
console.log('admin_upload_queue_test: OK');
