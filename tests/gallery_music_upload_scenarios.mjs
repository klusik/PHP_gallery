/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_music_upload_scenarios.mjs
 * Module Type: Regression Test
 * Purpose: Exercise original-audio admission and conservative upload retries.
 * Responsibilities: Preserve source identity, canonical envelopes and image counts.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL(
    '../public/assets/gallery-modules/gallery-music-upload.js', import.meta.url
), 'utf8');
const uploader = await readFile(new URL(
    '../public/assets/gallery-modules/admin-browser-upload.js', import.meta.url
), 'utf8');
const location = {href: 'https://gallery.example/galerie/',
    origin: 'https://gallery.example'};
let posts = [];
let rejectSecond = true;
let loseResponse = false;
const revision = 'a'.repeat(32);
const firstId = 'b'.repeat(32);
const secondId = 'c'.repeat(32);
const photo = new File(['photo'], 'photo.jpg', {type: 'image/jpeg'});
const pdf = new File(['pdf'], 'document.pdf', {type: 'application/pdf'});
const first = new File(['one'], 'one.mp3', {type: 'audio/mpeg'});
const second = new File(['two'], 'two.wav', {type: 'audio/wav'});

/** File input adapter retaining actual immutable File occurrences. */
class Input {
    /**
     * Retain the current shared selection without copying its sources.
     * @param {File[]} files Selected original occurrences.
     * @returns {void} Initialize the native-input substitute.
     */
    constructor(files) { this.files = files; }
}
const input = new Input([photo, pdf, first, second]);
const form = {
    dataset: {
        musicAttachmentUrl: '/galerie/index.php?page=admin_edit_gallery',
        musicPlaylistUrl: '/galerie/index.php?page=gallery_music',
        musicUploadError: 'upload failed', musicUploadUnknown: 'unknown result',
    },
    querySelector: selector => selector.includes('name="images[]"') ? input
        : selector.includes('csrf_token') ? {value: 'fixture-csrf'} : null,
    hasAttribute: () => false,
    setAttribute: () => {},
};
const base = {ok: true, uploaded: 1, scanned: 1, thumbnails: 2,
    image_ids: [7], mutation: {type: 'image.upload', entity: 'image',
        action: 'create', entity_ids: [7]},
    panel: {workflow: 'gallery-upload', keep_open: true},
    contexts: [{type: 'gallery', gallery_id: 42}], fallback: {}};
const context = vm.createContext({
    URL, File, Blob, FormData, location, HTMLInputElement: Input,
    assertAdminOperationCurrent: () => {},
    normalizeAdminMutationEnvelope: result => {
        assert.ok(result.mutation && Array.isArray(result.contexts));
        return result;
    },
    fetch: async (url, options) => {
        assert.equal(options.credentials, 'same-origin');
        if (!options.method) {
            assert.equal(new URL(url).searchParams.get('playlist'), '1');
            return {ok: true, json: async () => ({ok: true, revision})};
        }
        assert.equal(options.method, 'POST');
        const body = options.body;
        posts.push(body.get('music_file').name);
        assert.equal(body.get('csrf_token'), 'fixture-csrf');
        assert.equal(body.get('music_action'), 'upload');
        assert.equal(body.get('music_revision'), revision);
        if (loseResponse) throw new Error('lost transport');
        if (body.get('music_file').name === 'two.wav' && rejectSecond) {
            rejectSecond = false;
            return {ok: false, status: 422,
                json: async () => ({ok: false, message: 'try again'})};
        }
        return {ok: true, status: 200, json: async () => ({
            ...base, uploaded: undefined, music_revision: revision,
            track_id: body.get('music_file').name === 'one.mp3'
                ? firstId : secondId,
            mutation: {type: 'gallery.music.upload', entity: 'gallery',
                action: 'update', entity_ids: [42]},
        })};
    },
});
const transformed = source.replace(/^import .*;\s*$/gm, '')
    .replace(/^export (async function|function) /gm, '$1 ');
vm.runInContext(transformed, context);
const operation = {running: true};
await assert.rejects(context.uploadGalleryMusicFiles(
    form, [first, second], 42, operation, base
), /try again/);
const result = await context.uploadGalleryMusicFiles(
    form, [first, second], 42, operation, base
);
assert.deepEqual(posts, ['one.mp3', 'two.wav', 'two.wav'],
    'Retry must never re-upload an acknowledged original');
assert.equal(result.uploaded, 1);
assert.equal(result.thumbnails, 2);
assert.equal(result.mutation_envelopes.length, 3);
assert.equal(result.music_tracks_uploaded, 2);
assert.equal(result.mutation.type, 'image.upload');
assert.equal(result.panel.keep_open, true);
await assert.rejects(context.uploadGalleryMusicFiles(
    form, [second, first], 42, operation
), /unknown result/, 'Changed source order must not reuse an upload attempt');

loseResponse = true;
posts = [];
const uncertain = {running: true};
await assert.rejects(context.uploadGalleryMusicFiles(
    form, [first], 42, uncertain
), /unknown result/);
await assert.rejects(context.uploadGalleryMusicFiles(
    form, [first], 42, uncertain
), /unknown result/);
assert.deepEqual(posts, ['one.mp3'],
    'An unknown write must require reconciliation before another upload');

// Load the actual chooser partition and attachment-only dispatcher with the
// image-preparation switch off. No image worker adapter is provided: entering
// that path would fail, protecting originals from accidental image decoding.
context.browserUploadConfig = () => ({enabled: false});
context.throwIfUploadStreamAborted = () => {};
context.selectedBrowserGalleryId = () => 42;
context.compareUploadFilesByDefaultFolderOrder = () => 0;
context.i18n = (_key, fallback) => fallback;
context.form = form;
vm.runInContext(uploader.replace(/^import .*;\s*$/gm, '')
    .replace(/^export (async function|function) /gm, '$1 ')
    .replaceAll('import.meta.url', JSON.stringify(location.href)), context);
assert.deepEqual(Array.from(context.selectedBrowserUploadFiles(form)), [photo]);
assert.deepEqual(Array.from(context.selectedBrowserUploadMusicFiles(form)),
    [first, second]);
assert.deepEqual(Array.from(context.selectedBrowserUploadPdfFiles(form)), [pdf]);
input.files = [first];
assert.equal(context.browserUploadRequested(form), true);
// Rebind only configuration owners; production declarations above shadow the
// context adapters. The audio-only path deliberately never calls a worker.
vm.runInContext(`browserUploadConfig = () => ({enabled: false});
    selectedBrowserGalleryId = () => 42;`, context);
loseResponse = false;
const musicOnly = await context.runBrowserGalleryUpload(
    form, {querySelector: () => null}, {running: true, prepared: null}
);
assert.equal(musicOnly.music_tracks_uploaded, 1);
assert.equal(musicOnly.uploaded, 0);
console.log('Gallery music upload partition, envelopes and retry tests passed.');
