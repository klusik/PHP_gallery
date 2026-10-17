/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/gallery-music-upload.js
 * Module Type: Browser Module
 * Purpose: Attach selected audio through the ordinary gallery upload workflow.
 * Responsibilities: Keep original files, exact retry ownership and canonical
 *     envelopes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {assertAdminOperationCurrent} from './admin-operation-keys.js?v=20261012-pdf-files-v1';
import {normalizeAdminMutationEnvelope} from './admin-mutation-completion.js?v=20261016-three-state-covers-v1';

/**
 * Identify audio before image decoding, using MIME hints or supported
 *     containers.
 * @param {File} file Original selected browser file.
 * @returns {boolean} Whether the source belongs to protected audio admission.
 */
export function isBrowserUploadAudioFile(file) {
    return String(file?.type || '').toLowerCase().startsWith('audio/')
        || /\.(mp3|m4a|aac|ogg|oga|opus|wav|flac|webm|weba)$/i.test(String(file?.name || ''));
}

/**
 * Retain source and acknowledgment boundaries for one selected audio
 *     occurrence.
 * @typedef {Object} MusicUploadRecord
 * @property {File} file Original selected source, never copied or
 *     reconstructed.
 * @property {import('./admin-side-panel.js').AdminPanelResponse|null} result
 *     Acknowledged canonical response.
 * @property {boolean} pending Whether a submitted upload outcome is still
 *     unknown.
 */
/**
 * Exact selected attempts bound to an existing upload intent.
 * @type {WeakMap<object,{
 *     galleryId:number,revision:string,records:MusicUploadRecord[]
 * }>}
 */
const attempts = new WeakMap();

/**
 * Attach original music after ordinary photo/PDF work without replaying
 *     acknowledgments.
 * @param {HTMLFormElement} form Existing-gallery upload form with prepared
 *     endpoints and CSRF.
 * @param {File[]} files Audio occurrences in shared chooser order.
 * @param {number} galleryId Selected physical gallery identity.
 * @param {import('./admin-operation-keys.js').AdminOperationIntent}
 *     operation Existing intent owning retry state.
 * @param {import('./admin-side-panel.js').AdminPanelResponse|null}
 *     baseResult Earlier acknowledged image/PDF envelope, if present.
 * @returns {Promise<import('./admin-side-panel.js').AdminPanelResponse>}
 *     Canonical aggregate retaining each original mutation response and
 *     image counts.
 */
export async function uploadGalleryMusicFiles(
    form, files, galleryId, operation, baseResult = null
) {
    const failure = form.dataset.musicUploadError
        || 'Music upload failed. Reopen the gallery editor.';
    const unknown = form.dataset.musicUploadUnknown
        || 'The music response is unknown. Check the gallery before retrying.';
    const endpoint = new URL(String(form.dataset.musicAttachmentUrl || ''), location.href);
    const playlistUrl = new URL(String(form.dataset.musicPlaylistUrl || ''), location.href);
    if (!form.dataset.musicAttachmentUrl || !form.dataset.musicPlaylistUrl
        || endpoint.origin !== location.origin || playlistUrl.origin !== location.origin
        || !Number.isSafeInteger(galleryId) || galleryId < 1) throw new Error(failure);
    endpoint.searchParams.set('id', String(galleryId));
    playlistUrl.searchParams.set('id', String(galleryId));
    playlistUrl.searchParams.set('playlist', '1');
    let attempt = attempts.get(operation);
    if (!attempt) {
        attempt = {galleryId, revision: '', records: files.map(file => ({
            file, result: null, pending: false,
        }))};
        attempts.set(operation, attempt);
    }
    if (attempt.galleryId !== galleryId || attempt.records.length !== files.length
        || attempt.records.some((record, index) => record.file !== files[index])) {
        throw new Error(unknown);
    }
    if (!attempt.revision) {
        assertAdminOperationCurrent(operation);
        try {
            const response = await fetch(playlistUrl.href, {
                credentials: 'same-origin', cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const result = await response.json();
            assertAdminOperationCurrent(operation);
            if (!response.ok || result.ok !== true
                || !/^[a-f0-9]{32}$/.test(result.revision)) {
                throw new Error(failure);
            }
            attempt.revision = result.revision;
        } catch { throw new Error(failure); }
    }
    for (const record of attempt.records) {
        assertAdminOperationCurrent(operation);
        if (record.result) continue;
        if (record.pending) throw new Error(unknown);
        const body = new FormData();
        body.set('id', String(galleryId));
        body.set('csrf_token', String(form.querySelector('[name="csrf_token"]')?.value || ''));
        body.set('ajax', '1');
        body.set('music_action', 'upload');
        body.set('music_revision', attempt.revision);
        body.set('music_file', record.file, record.file.name);
        record.pending = true;
        operation.unresolved = true;
        let response;
        let result;
        try {
            response = await fetch(endpoint.href, {method: 'POST', body, credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
            result = await response.json();
        } catch { throw new Error(unknown); }
        if (response.status === 422 && result?.ok === false) {
            record.pending = false;
            throw new Error(String(result.message || failure));
        }
        if (!response.ok || !/^[a-f0-9]{32}$/.test(result?.music_revision)
            || !/^[a-f0-9]{32}$/.test(result?.track_id)) {
            throw new Error(unknown);
        }
        try { normalizeAdminMutationEnvelope(result); }
        catch { throw new Error(unknown); }
        record.pending = false;
        record.result = result;
        attempt.revision = result.music_revision;
        assertAdminOperationCurrent(operation);
    }
    const previous = baseResult?.mutation_envelopes || (baseResult ? [baseResult] : []);
    const envelopes = [...previous, ...attempt.records.map(record => record.result)];
    const last = envelopes[envelopes.length - 1];
    const contexts = new Map();
    for (const envelope of envelopes) for (const context of envelope.contexts || []) {
        contexts.set(JSON.stringify(context), context);
    }
    return {...baseResult, ...last, gallery_id: galleryId,
        mutation: baseResult?.mutation || last.mutation,
        contexts: Array.from(contexts.values()), mutation_envelopes: envelopes,
        image_ids: baseResult?.image_ids || [], uploaded: Number(baseResult?.uploaded || 0),
        scanned: Number(baseResult?.scanned || 0), thumbnails: Number(baseResult?.thumbnails || 0),
        thumbnail_failed: Number(baseResult?.thumbnail_failed || 0),
        music_tracks_uploaded: files.length,
        music_track_ids: attempt.records.map(record => record.result.track_id),
        panel: {...last.panel, keep_open: true}};
}
