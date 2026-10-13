/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/browser-upload-archive-stream.js
 * Module Type: Browser ZIP Source Adapter
 * Purpose: Pull one checked image at a time from the existing ZIP worker into the existing File-stream uploader.
 * Responsibilities:
 *   - Keep archive payloads disk-backed and reuse worker admission/CRC/format policy.
 *   - Preserve an immutable source and cursor across cancellation and exact upload retries.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {appendUploadProgressLog, i18n as globalI18n, i18nForElement} from './admin-core.js?v=20261003-scoped-i18n-v1';

/** Describe an admitted entry without copying its image bytes.
 * @typedef {{index:number,name:string,path:string,size:number}} ArchiveStreamEntry
 */
/** Describe worker replies used by the bounded archive source.
 * @typedef {{entries?:ArchiveStreamEntry[],skipped?:number,reasons?:Record<string,number>,entry?:{name:string,path:string,blob:Blob}|null}} ArchiveStreamReply
 */
/** Describe the persistent metadata/cursor and disposable reader of one archive.
 * @typedef {Object} BrowserArchiveStream
 * @property {number} pageCount Total admitted images, reduced for counted integrity skips.
 * @property {number} startIndex Zero-based next not-yet-prepared image; set by the existing upload cursor.
 * @property {AbortSignal} signal Current explicit attempt cancellation.
 * @property {ArchiveStreamEntry[]} entries Ordered metadata only, retaining worker indices after counted skips.
 * @property {function():AsyncIterator<File>} [Symbol.asyncIterator] Pull one original at a time.
 * @property {function():Promise<void>} cancel Stop the disposable worker without deleting the source.
 * @property {function():Promise<void>} dispose Release the worker; metadata stays available for retry.
 */

/** Open an immutable archive with the existing extraction policy and return its pull-driven File source.
 * @param {File} file Closed OPFS snapshot or ordinary disk-backed source archive.
 * @param {{uploadLimitBytes:number}} config Existing effective server per-image admission limit.
 * @param {HTMLElement} progress Original form's progress/log surface.
 * @param {AbortSignal} signal First attempt cancellation signal.
 * @returns {Promise<BrowserArchiveStream>} Metadata-only stream; malformed or empty archives reject before server writes.
 */
export async function createBrowserArchiveStream(file, config, progress, signal) {
    const i18n = (key, fallback = '', parameters = {}) => i18nForElement(progress, key, globalI18n(key, fallback), parameters);
    let worker = null;
    let requestId = 0;
    let stream;
    const limits = {maximumEntryBytes: config.uploadLimitBytes};
    const stop = async () => { if (worker) worker.terminate(); worker = null; };
    const request = (action, extra = {}) => new Promise((resolve, reject) => {
        const ownedWorker = worker;
        const id = ++requestId;
        const activeSignal = stream?.signal || signal;
        const cleanup = () => {
            ownedWorker.removeEventListener('message', message);
            ownedWorker.removeEventListener('error', failed);
            activeSignal.removeEventListener('abort', aborted);
        };
        const failed = () => { cleanup(); stop(); reject(new Error(i18n('admin.icloud.zip_invalid'))); };
        const aborted = () => { cleanup(); stop(); reject(new Error(i18n('admin.icloud.cancelled'))); };
        const message = event => {
            if (event.data?.id !== id) return;
            cleanup();
            if (!event.data.ok) reject(new Error(i18n('admin.icloud.zip_' + String(event.data.code || 'invalid')), {cause: new Error(String(event.data.error || 'Archive reader failed.'))}));
            else resolve(event.data.result);
        };
        if (activeSignal.aborted) { aborted(); return; }
        ownedWorker.addEventListener('message', message);
        ownedWorker.addEventListener('error', failed);
        activeSignal.addEventListener('abort', aborted, {once: true});
        ownedWorker.postMessage({id, action, ...extra});
    });
    const open = async () => {
        if (worker) return null;
        worker = new Worker(new URL('./browser-image-worker.js?v=20261013-icloud-auto-v2', import.meta.url));
        try { return await request('openUploadZip', {zipBlob: file, limits}); }
        catch (error) { await stop(); throw error; }
    };
    const inspected = await open();
    if (!inspected.entries?.length) { await stop(); throw new Error(i18n('admin.browser_upload.zip_no_supported_images')); }
    for (const [reason, count] of Object.entries(inspected.reasons || {})) {
        appendUploadProgressLog(progress, i18n('admin.icloud.skipped', '', {count, reason: i18n('admin.icloud.skip_' + reason)}));
    }
    stream = {pageCount: inspected.entries.length, startIndex: 0, signal, entries: inspected.entries, cancel: stop, dispose: stop};
    stream[Symbol.asyncIterator] = async function* () {
        if (stream.startIndex >= stream.entries.length) return;
        await open();
        let index = stream.startIndex;
        while (index < stream.entries.length) {
            const descriptor = stream.entries[index];
            const result = await request('readUploadZipEntry', {entryIndex: descriptor.index});
            if (!result.entry) {
                stream.entries.splice(index, 1);
                stream.pageCount = stream.entries.length;
                appendUploadProgressLog(progress, i18n('admin.icloud.skipped', '', {count: 1, reason: i18n('admin.icloud.skip_integrity')}));
                continue;
            }
            const entry = result.entry;
            const original = new File([entry.blob], entry.name, {lastModified: file.lastModified});
            index++;
            yield original;
        }
    };
    return stream;
}
