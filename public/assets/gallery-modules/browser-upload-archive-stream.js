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
 * @property {function(ArchiveStreamEntry):Promise<File|null>} readEntry Read and verify one descriptor; null counts an integrity skip.
 * @property {function():AsyncIterator<File>} [Symbol.asyncIterator] Pull one original at a time.
 * @property {function():Promise<void>} cancel Stop the disposable worker without deleting the source.
 * @property {function():Promise<void>} dispose Release the worker; metadata stays available for retry.
 */

/** Open an immutable archive with the existing extraction policy and return its pull-driven File source.
 * @param {File} file Closed OPFS snapshot or ordinary disk-backed source archive.
 * @param {{uploadLimitBytes:number,allowEmpty?:boolean}} config Server per-image limit; manual mixed selections can retain an empty archive's counted skips.
 * @param {HTMLElement} progress Original form's progress/log surface.
 * @param {AbortSignal} signal First attempt cancellation signal.
 * @returns {Promise<BrowserArchiveStream>} Metadata-only stream; malformed archives and empty automatic sources reject before server writes.
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
    if (!inspected.entries?.length && !config.allowEmpty) { await stop(); throw new Error(i18n('admin.browser_upload.zip_no_supported_images')); }
    for (const [reason, count] of Object.entries(inspected.reasons || {})) {
        appendUploadProgressLog(progress, i18n('admin.icloud.skipped', '', {count, reason: i18n('admin.icloud.skip_' + reason)}));
    }
    stream = {pageCount: inspected.entries.length, startIndex: 0, signal, entries: inspected.entries, cancel: stop, dispose: stop};
    stream.readEntry = async descriptor => {
        await open();
        const result = await request('readUploadZipEntry', {entryIndex: descriptor.index});
        if (!result.entry) return null;
        return new File([result.entry.blob], result.entry.name, {lastModified: file.lastModified});
    };
    stream[Symbol.asyncIterator] = async function* () {
        if (stream.startIndex >= stream.entries.length) return;
        await open();
        let index = stream.startIndex;
        while (index < stream.entries.length) {
            const descriptor = stream.entries[index];
            const original = await stream.readEntry(descriptor);
            if (!original) {
                stream.entries.splice(index, 1);
                stream.pageCount = stream.entries.length;
                appendUploadProgressLog(progress, i18n('admin.icloud.skipped', '', {count: 1, reason: i18n('admin.icloud.skip_integrity')}));
                continue;
            }
            index++;
            yield original;
        }
    };
    return stream;
}

/** Describe one selected original or archive descriptor without extracted bytes.
 * @typedef {{name:string,webkitRelativePath?:string,file?:File,reader?:BrowserArchiveStream,entry?:ArchiveStreamEntry}} SelectionStreamEntry
 */
/** Describe the manual selection's metadata and retry cursor.
 * @typedef {Object} BrowserUploadSelectionStream
 * @property {number} pageCount Admitted originals, reduced for integrity skips.
 * @property {number} startIndex Next unstaged original in the retained ordering.
 * @property {AbortSignal} signal Current attempt's cancellation signal.
 * @property {function():AsyncIterator<File>} [Symbol.asyncIterator] Read one original on demand.
 * @property {function():Promise<void>} cancel Stop the current archive reader.
 * @property {function():Promise<void>} dispose Release the reader, retaining metadata for retry.
 */

/** Inspect selected ZIPs before upload and stream their images among ordinary Files.
 * @param {File[]} files Immutable sources in the selected drawer order.
 * @param {{uploadLimitBytes:number}} config Effective server per-image limit.
 * @param {HTMLElement} progress Original upload progress and localized log.
 * @param {AbortSignal} signal Initial explicit attempt cancellation.
 * @param {(function(SelectionStreamEntry,SelectionStreamEntry):number)|null} compare Natural folder ordering, or null to preserve drawer order.
 * @returns {Promise<BrowserUploadSelectionStream>} Metadata-only source; invalid archives reject before server writes.
 */
export async function createBrowserUploadSelectionStream(files, config, progress, signal, compare = null) {
    /** @type {SelectionStreamEntry[]} Ordered descriptors; no extracted payloads. */
    const entries = [];
    for (const file of files) {
        if (signal.aborted) throw new Error(i18nForElement(progress, 'admin.icloud.cancelled'));
        const zip = /\.zip$/i.test(file.name)
            || ['application/zip', 'application/x-zip-compressed'].includes(file.type.toLowerCase());
        if (!zip) {
            entries.push({name: file.name, webkitRelativePath: file.webkitRelativePath, file});
            continue;
        }
        appendUploadProgressLog(progress, i18nForElement(progress,
            'admin.browser_upload.log_extracting_zip', '',
            {name: file.name, bytes: file.size.toLocaleString() + ' B'}));
        const reader = await createBrowserArchiveStream(file, {...config, allowEmpty: true}, progress, signal);
        // Inspection retains directory metadata only. Keep at most one live
        // reader during consumption, even when natural sorting interleaves ZIPs.
        await reader.dispose();
        for (const entry of reader.entries) entries.push({name: entry.name, reader, entry});
    }
    if (compare) entries.sort(compare);
    let activeReader = null;
    const dispose = async () => {
        if (activeReader) await activeReader.dispose();
        activeReader = null;
    };
    const stream = {pageCount: entries.length, startIndex: 0, signal, cancel: dispose, dispose};
    stream[Symbol.asyncIterator] = async function* () {
        let index = stream.startIndex;
        while (index < entries.length) {
            if (stream.signal.aborted) throw new Error(i18nForElement(progress, 'admin.icloud.cancelled'));
            const descriptor = entries[index];
            if (activeReader !== (descriptor.reader || null)) {
                await dispose();
                activeReader = descriptor.reader || null;
            }
            if (activeReader) activeReader.signal = stream.signal;
            const file = descriptor.file || await activeReader.readEntry(descriptor.entry);
            if (!file) {
                entries.splice(index, 1);
                stream.pageCount = entries.length;
                appendUploadProgressLog(progress, i18nForElement(progress,
                    'admin.icloud.skipped', '',
                    {count: 1, reason: i18nForElement(progress, 'admin.icloud.skip_integrity')}));
                continue;
            }
            index++;
            yield file;
        }
    };
    return stream;
}
