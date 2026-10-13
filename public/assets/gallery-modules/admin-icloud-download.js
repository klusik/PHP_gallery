/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-icloud-download.js
 * Module Type: Browser Acquisition Service
 * Purpose: Acquire anonymous public Apple photo exports directly into owned browser disk storage.
 * Responsibilities:
 *   - Validate the observed Photos sharing protocol without credentials or a Gallery proxy.
 *   - Request compatible exports and stream bytes with backpressure, cancellation and quota cleanup.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {i18n} from './admin-core.js?v=20260614-upload-original-diagnostics-v1';

/** Bound one metadata response before JSON decoding.
 * Type: number. Units: bytes. Scope: one Apple metadata response.
 * Consumers: readIcloudJson. Rationale: a bounded page must not allocate an unbounded server reply.
 */
const maximumMetadataBytes = 4 * 1024 * 1024;
/** Bound the selected record identifiers retained for one export.
 * Type: number. Units: asset records. Scope: one source import.
 * Consumers: prepareIcloudExport. Rationale: retain the existing ZIP owner's 10,000-entry admission ceiling.
 */
const maximumAssets = 10000;
/** Bound each Apple query page independently of the complete source size.
 * Type: number. Units: query results. Scope: one metadata page.
 * Consumers: prepareIcloudExport. Rationale: 200 results bound metadata while continuation markers cover larger shares.
 */
const metadataPageSize = 200;
/** Bound archive acquisition to the existing classic ZIP address space.
 * Type: number. Units: bytes. Scope: one downloaded archive.
 * Consumers: stageIcloudZip. Rationale: the worker rejects ZIP64; larger archives cannot be admitted by that owner.
 */
const maximumArchiveBytes = 4 * 1024 * 1024 * 1024 - 1;
/** Bound each awaited disk write without duplicating the network chunk.
 * Type: number. Units: bytes. Scope: one OPFS write.
 * Consumers: stageIcloudZip. Rationale: one MiB subarray writes provide backpressure through the browser file writer.
 */
const maximumWriteBytes = 1024 * 1024;
/** Bound stalled metadata and archive reads without limiting an active large download's duration.
 * Type: number. Units: milliseconds. Scope: one fetch/read waiting for progress.
 * Consumers: readIcloudJson and stageIcloudZip. Rationale: one minute without bytes yields a retryable source failure.
 */
const sourceIdleTimeout = 60000;
/** Bound source pagination even when an upstream page contains no assets.
 * Type: number. Units: pages. Scope: one resolved share.
 * Consumers: prepareIcloudExport. Rationale: 100 pages admit 10,000 paired asset/master records without unbounded empty-marker requests.
 */
const maximumMetadataPages = 100;
/** Bound opaque identifiers before retaining them across pages or building a request URL.
 * Type: number. Units: UTF-16 code units. Scope: one record/zone identifier.
 * Consumers: prepareIcloudExport. Rationale: 1,024-character IDs bound the retained 10,000-record list independently of response page sizes.
 */
const maximumIdentifierCharacters = 1024;
/** Bound anonymous tokens and signed destination URLs without persisting either.
 * Type: number. Units: UTF-16 code units. Scope: one token/URL.
 * Consumers: checkedIcloudDestination and prepareIcloudExport. Rationale: a 16 KiB transport bound admits signed URLs while refusing oversized opaque response fields.
 */
const maximumTransportCharacters = 16 * 1024;
/** Retire abandoned source files only after acquiring their exclusive import lock.
 * Type: number. Units: milliseconds. Scope: this origin's dedicated iCloud workspace.
 * Consumers: createIcloudWorkspace. Rationale: a one-day grace preserves interrupted work while released locks prove no current document owns an old file.
 */
const abandonedArchiveAge = 24 * 60 * 60 * 1000;

/** Describe the consumed CloudKit record identity; other photo fields remain unused.
 * @typedef {{recordName:string,recordType?:string}} IcloudRecord
 */
/** Describe a resolved public share without retaining owner identities or media metadata.
 * @typedef {{databaseScope:string,zoneID:{zoneName:string,ownerRecordName:string,zoneType:string},share?:IcloudRecord,requireAppleLogin?:boolean,anonymousPublicAccess?:{token:string,databasePartition:string}}} IcloudResolution
 */
/** Describe only the protocol fields consumed by the acquisition owner.
 * @typedef {{results?:IcloudResolution[],records?:IcloudRecord[],continuationMarker?:string,downloadURL?:string,serverErrorCode?:string,nextAccessToken?:string}} IcloudReply
 */
/** Describe a checked Apple export; its bearer URL never crosses into Gallery transport.
 * @typedef {{url:string,assetCount:number}} IcloudExport
 */
/** Describe the immutable disk-backed archive and its document-owned cleanup.
 * @typedef {{file:File,assetCount:number,cleanup:function():Promise<void>}} IcloudArchive
 */
/** Describe source progress using only byte counters and public phase names.
 * @typedef {{phase:'checking'|'exporting'|'downloading',loaded:number,total:number|null}} IcloudDownloadProgress
 */

/** Create a safe localized failure without including a URL, token or Apple reason string.
 * @param {string} key Existing iCloud localization suffix.
 * @returns {Error & {icloudKey:string}} Safe error with a stable message key for the owning form's Admin language scope.
 */
function icloudFailure(key) {
    const error = new Error(i18n('admin.icloud.' + key, 'The iCloud import could not continue.'));
    error.icloudKey = 'admin.icloud.' + key;
    return error;
}

/** Require a credential-free HTTPS destination belonging to the observed Apple service class.
 * @param {string} value Untrusted URL returned by Apple.
 * @param {'metadata'|'archive'} kind The permitted endpoint owner.
 * @returns {URL} Checked URL; throws before fetching an unexpected host or scheme.
 */
function checkedIcloudDestination(value, kind) {
    if (typeof value !== 'string' || value.length > maximumTransportCharacters) throw icloudFailure('protocol_failed');
    let url;
    try { url = new URL(value); } catch { throw icloudFailure('protocol_failed'); }
    const hostAllowed = kind === 'metadata'
        ? /^(?:p\d+-)?ckdatabasews\.icloud\.com$/i.test(url.hostname)
        : /^(?:[a-z0-9-]+\.)+icloud-content\.com$/i.test(url.hostname);
    if (url.protocol !== 'https:' || url.username || url.password || url.port || !hostAllowed) throw icloudFailure('protocol_failed');
    return url;
}

/** Read a bounded JSON response and refuse redirects, credentials and raw upstream errors.
 * @param {URL} url Checked Apple metadata endpoint.
 * @param {{shortGUIDs?:Array<{value:string}>,zoneID?:IcloudResolution['zoneID'],query?:{recordType:string,filterBy:Array<{fieldName:string,comparator:string,fieldValue:{value:string,type:string}}>},resultsLimit?:number,continuationMarker?:string,includeRecords?:string[],archiveName?:string,shareName?:string,pluginFields?:{itemTypes:{value:string[],type:string},codecs:{value:string[],type:string}}}} body Observed anonymous protocol request.
 * @param {AbortSignal} signal Document-owned cancellation signal.
 * @returns {Promise<IcloudReply>} Parsed bounded reply; downstream consumers validate the required fields.
 */
async function readIcloudJson(url, body, signal) {
    const controller = new AbortController();
    const abort = () => controller.abort();
    signal.addEventListener('abort', abort, {once: true});
    let timer = setTimeout(abort, sourceIdleTimeout);
    let reader;
    try {
        if (signal.aborted) throw icloudFailure('cancelled');
        const response = await fetch(url.href, {method: 'POST', headers: {'Content-Type': 'text/plain'}, body: JSON.stringify(body), credentials: 'omit', referrerPolicy: 'no-referrer', cache: 'no-store', redirect: 'error', signal: controller.signal});
        if (!response.ok) throw icloudFailure([401, 403, 404, 410].includes(response.status) ? 'source_unavailable' : 'protocol_failed');
        if (!response.body) throw icloudFailure('protocol_failed');
        reader = response.body.getReader();
        const chunks = [];
        let received = 0;
        while (true) {
            clearTimeout(timer);
            timer = setTimeout(abort, sourceIdleTimeout);
            const next = await reader.read();
            if (next.done) break;
            received += next.value.byteLength;
            if (received > maximumMetadataBytes) throw icloudFailure('protocol_failed');
            chunks.push(next.value);
        }
        const bytes = new Uint8Array(received);
        let offset = 0;
        for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
        let data;
        try { data = JSON.parse(new TextDecoder().decode(bytes)); } catch { throw icloudFailure('protocol_failed'); }
        if (!data || typeof data !== 'object' || Array.isArray(data)) throw icloudFailure('protocol_failed');
        if (data.serverErrorCode) throw icloudFailure('source_unavailable');
        const refreshed = response.headers.get('X-CloudKit-PublicAccess-AuthToken');
        if (refreshed) {
            if (refreshed.length > maximumTransportCharacters) throw icloudFailure('protocol_failed');
            data.nextAccessToken = refreshed;
        } else delete data.nextAccessToken;
        return data;
    } catch (error) {
        if (signal.aborted) throw icloudFailure('cancelled');
        if (error instanceof TypeError || controller.signal.aborted) throw icloudFailure('source_network_failed');
        throw error;
    } finally {
        clearTimeout(timer);
        signal.removeEventListener('abort', abort);
        if (reader) { await reader.cancel().catch(() => {}); reader.releaseLock(); }
        controller.abort();
    }
}

/** Resolve the two public source products independently and request Apple's compatible export.
 * @param {import('./admin-icloud-source.js').IcloudSource} source Validated Photos Link or Shared Album.
 * @param {AbortSignal} signal Active import cancellation.
 * @param {function(IcloudDownloadProgress):void} onProgress Safe phase/byte observer.
 * @returns {Promise<IcloudExport>} Checked signed archive URL and source asset count; no credentials are requested.
 */
export async function prepareIcloudExport(source, signal, onProgress) {
    const sourceUrl = new URL(source.url);
    const token = sourceUrl.hash ? sourceUrl.hash.replace(/^#(?:\/sc,)?/, '').replace(/\/$/, '') : sourceUrl.pathname.split('/').filter(Boolean).at(-1);
    if (!/^[A-Za-z0-9_-]+$/.test(token)) throw icloudFailure('invalid');
    const resolveUrl = new URL('https://ckdatabasews.icloud.com/database/1/com.apple.photos.cloud/production/public/records/resolve');
    const params = new URLSearchParams({remapEnums: 'true', getCurrentSyncToken: 'true', sharing_url_key: token});
    resolveUrl.search = params.toString();
    onProgress({phase: 'checking', loaded: 0, total: null});
    const resolved = await readIcloudJson(resolveUrl, {shortGUIDs: [{value: token}]}, signal);
    const item = resolved.results?.[0];
    if (!item || item.requireAppleLogin || typeof item.anonymousPublicAccess?.token !== 'string' || !item.anonymousPublicAccess.token || item.anonymousPublicAccess.token.length > maximumTransportCharacters) throw icloudFailure('source_unavailable');
    const scope = String(item.databaseScope || '').toLowerCase();
    const zone = item.zoneID;
    if (!['public', 'shared'].includes(scope) || !zone || !['zoneName', 'ownerRecordName', 'zoneType'].every(key => typeof zone[key] === 'string' && zone[key].length > 0 && zone[key].length <= maximumIdentifierCharacters)) throw icloudFailure('protocol_failed');
    const partition = checkedIcloudDestination(item.anonymousPublicAccess.databasePartition, 'metadata');
    params.set('publicAccessAuthToken', item.anonymousPublicAccess.token);
    const queryUrl = new URL(`/database/1/com.apple.photos.cloud/production/${scope}/records/query`, partition.origin);
    queryUrl.search = params.toString();
    const body = {zoneID: zone, query: {recordType: source.type === 'shared-album' ? 'CPLAssetAndMasterByAddedDate' : 'CPLAssetAndMasterByAssetDateWithoutHiddenOrDeleted', filterBy: [{fieldName: 'direction', comparator: 'EQUALS', fieldValue: {value: 'ASCENDING', type: 'STRING'}}]}, resultsLimit: metadataPageSize};
    const records = [];
    const seen = new Set();
    const markers = new Set();
    do {
        const reply = await readIcloudJson(queryUrl, body, signal);
        if (!Array.isArray(reply.records)) throw icloudFailure('protocol_failed');
        for (const record of reply.records) {
            if (!record || typeof record !== 'object') throw icloudFailure('protocol_failed');
            if (record.recordType !== 'CPLAsset' || typeof record.recordName !== 'string') continue;
            if (!record.recordName || record.recordName.length > maximumIdentifierCharacters) throw icloudFailure('protocol_failed');
            if (seen.has(record.recordName)) throw icloudFailure('protocol_failed');
            seen.add(record.recordName);
            records.push(record.recordName);
            if (records.length > maximumAssets) throw icloudFailure('zip_entries');
        }
        if (reply.nextAccessToken) { params.set('publicAccessAuthToken', reply.nextAccessToken); queryUrl.search = params.toString(); }
        if (!reply.continuationMarker) break;
        if (typeof reply.continuationMarker !== 'string' || reply.continuationMarker.length > maximumTransportCharacters || markers.has(reply.continuationMarker) || markers.size >= maximumMetadataPages - 1) throw icloudFailure('protocol_failed');
        markers.add(reply.continuationMarker);
        body.continuationMarker = reply.continuationMarker;
    } while (true);
    if (!records.length) throw icloudFailure('source_empty');
    const exportUrl = new URL(`/database/1/com.apple.photos.cloud/production/${scope}/records/zip/prepare`, partition.origin);
    exportUrl.search = params.toString();
    onProgress({phase: 'exporting', loaded: 0, total: null});
    const exported = await readIcloudJson(exportUrl, {zoneID: zone, includeRecords: records, archiveName: 'Gallery-iCloud-import.zip', ...(item.share?.recordName ? {shareName: item.share.recordName} : {}), pluginFields: {itemTypes: {value: ['public.jpeg', 'public.png', 'com.compuserve.gif', 'com.apple.quicktime-movie', 'public.mpeg-4'], type: 'STRING_LIST'}, codecs: {value: ['H.264'], type: 'STRING_LIST'}}}, signal);
    if (typeof exported.downloadURL !== 'string') throw icloudFailure('protocol_failed');
    return {url: checkedIcloudDestination(exported.downloadURL, 'archive').href, assetCount: records.length};
}

/** Create one locked archive workspace and remove old unlocked files from this owner's exact namespace.
 * @param {FileSystemDirectoryHandle} directory Dedicated origin-private iCloud directory.
 * @returns {Promise<{name:string,cleanup:function():Promise<void>}>} Unique file name and idempotent removal/release callback; active files in other documents remain locked.
 */
async function createIcloudWorkspace(directory) {
    const prefix = 'php-gallery:icloud:';
    const entries = directory.entries();
    while (true) {
        const next = await entries.next();
        if (next.done) break;
        const [name, handle] = next.value;
        if (handle.kind !== 'file' || !/^import-\d+-[a-f0-9-]+\.zip$/.test(name)) continue;
        await navigator.locks.request(prefix + name, {ifAvailable: true}, async lock => {
            if (!lock) return;
            try {
                const file = await handle.getFile();
                if (Date.now() - file.lastModified > abandonedArchiveAge) await directory.removeEntry(name);
            } catch (error) {
                // Another completed owner may have removed this enumerated file
                // before this document acquired its lock.
                if (error.name !== 'NotFoundError') throw error;
            }
        });
    }
    const name = 'import-' + Date.now() + '-' + crypto.randomUUID() + '.zip';
    let release;
    let ready;
    let fail;
    const held = new Promise(resolve => { release = resolve; });
    const acquired = new Promise((resolve, reject) => { ready = resolve; fail = reject; });
    const owner = navigator.locks.request(prefix + name, async () => { ready(); await held; });
    owner.catch(fail);
    await acquired;
    let removed = false;
    return {name, cleanup: async () => {
        if (removed) return;
        try { await directory.removeEntry(name); }
        catch (error) { if (error.name !== 'NotFoundError') throw error; }
        finally { removed = true; release(); await owner; }
    }};
}

/** Stream a checked Apple archive into an owned OPFS file and retain only its immutable File reference.
 * @param {import('./admin-icloud-source.js').IcloudSource} source Recognized public source.
 * @param {AbortSignal} signal Document-owned cancellation.
 * @param {function(IcloudDownloadProgress):void} onProgress Byte counters; total is null when Apple supplies no length.
 * @returns {Promise<IcloudArchive>} Disk-backed archive with explicit cleanup; any incomplete download removes its file.
 */
export async function stageIcloudZip(source, signal, onProgress) {
    if (!navigator.storage?.getDirectory || !navigator.locks?.request || !window.isSecureContext) throw icloudFailure('disk_required');
    const exported = await prepareIcloudExport(source, signal, onProgress);
    const root = await navigator.storage.getDirectory();
    const directory = await root.getDirectoryHandle('php-gallery-icloud', {create: true});
    const workspace = await createIcloudWorkspace(directory);
    const {name, cleanup} = workspace;
    let writable;
    let reader;
    let complete = false;
    const controller = new AbortController();
    const abort = () => controller.abort();
    signal.addEventListener('abort', abort, {once: true});
    let timer = setTimeout(abort, sourceIdleTimeout);
    try {
        if (signal.aborted) throw icloudFailure('cancelled');
        const response = await fetch(exported.url, {credentials: 'omit', referrerPolicy: 'no-referrer', cache: 'no-store', redirect: 'error', signal: controller.signal});
        if (!response.ok || !response.body) throw icloudFailure('source_unavailable');
        checkedIcloudDestination(response.url || exported.url, 'archive');
        const lengthText = response.headers.get('Content-Length');
        const total = lengthText && /^\d+$/.test(lengthText) ? Number(lengthText) : null;
        if (total !== null && (!Number.isSafeInteger(total) || total <= 0 || total > maximumArchiveBytes)) throw icloudFailure('zip_size');
        const estimate = await navigator.storage.estimate();
        if (total !== null && Number.isFinite(estimate.quota) && total > estimate.quota - Number(estimate.usage || 0)) throw icloudFailure('storage_failed');
        const handle = await directory.getFileHandle(name, {create: true});
        writable = await handle.createWritable();
        reader = response.body.getReader();
        let received = 0;
        onProgress({phase: 'downloading', loaded: received, total});
        while (true) {
            clearTimeout(timer);
            timer = setTimeout(abort, sourceIdleTimeout);
            const next = await reader.read();
            if (next.done) break;
            received += next.value.byteLength;
            if (received > maximumArchiveBytes || (total !== null && received > total)) throw icloudFailure('zip_size');
            for (let offset = 0; offset < next.value.byteLength; offset += maximumWriteBytes) {
                if (signal.aborted) throw icloudFailure('cancelled');
                await writable.write(next.value.subarray(offset, offset + maximumWriteBytes));
            }
            if (signal.aborted) throw icloudFailure('cancelled');
            onProgress({phase: 'downloading', loaded: received, total});
        }
        if (!received || (total !== null && received !== total)) throw icloudFailure('download_incomplete');
        await writable.close();
        writable = null;
        const file = await handle.getFile();
        const signature = new Uint8Array(await file.slice(0, 4).arrayBuffer());
        if (signature[0] !== 0x50 || signature[1] !== 0x4b || signature[2] !== 3 || signature[3] !== 4) throw icloudFailure('zip_invalid');
        if (signal.aborted) throw icloudFailure('cancelled');
        complete = true;
        return {file, assetCount: exported.assetCount, cleanup};
    } catch (error) {
        if (signal.aborted) throw icloudFailure('cancelled');
        if (error.name === 'QuotaExceededError') throw icloudFailure('storage_failed');
        if (error instanceof TypeError || controller.signal.aborted) throw icloudFailure('source_network_failed');
        throw error;
    } finally {
        clearTimeout(timer);
        signal.removeEventListener('abort', abort);
        controller.abort();
        if (reader) { await reader.cancel().catch(() => {}); reader.releaseLock(); }
        if (writable) await writable.abort().catch(() => {});
        if (!complete) await cleanup();
    }
}
