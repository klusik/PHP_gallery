/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/browser_upload_zip_worker/icloud_download.mjs
 * Module Type: Regression Scenarios
 * Purpose: Exercise anonymous source protocol and disk-stream lifecycle with synthetic responses and storage.
 * Responsibilities:
 *   - Refuse foreign/private/expired/schema-invalid sources and preserve credentials-free requests.
 *   - Verify pull/write backpressure, byte bounds, quota, cancellation and locked orphan cleanup.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

/** Describe acquisition switches and observed boundary operations.
 * @typedef {{expired?:boolean,privateSource?:boolean,foreignPartition?:boolean,foreignArchive?:boolean,repeatingMarker?:boolean,networkFailure?:boolean,oversizedMetadata?:boolean,diskUnsupported?:boolean,insecure?:boolean,quota?:number,declaredLength?:number|null,chunks?:Uint8Array[],beforeWrite?:(function():Promise<void>),existing?:Map<string,File>}} AcquisitionOptions
 */
/** Describe the production API with controlled read-only network and filesystem adapters.
 * @typedef {{api:{prepareIcloudExport:typeof import('../../public/assets/gallery-modules/admin-icloud-download.js').prepareIcloudExport,stageIcloudZip:typeof import('../../public/assets/gallery-modules/admin-icloud-download.js').stageIcloudZip},state:{requests:Array<{url:string,init:{method?:string,credentials:string,referrerPolicy:string,redirect:string,body?:string,headers?:Record<string,string>}}>,files:Map<string,File>,reads:number,writes:number,inFlight:number,maximumInFlight:number,removed:string[],locks:Set<string>}}} AcquisitionHarness
 */

/** Load the real protocol/staging owner with synthetic streaming responses and an OPFS-shaped store.
 * @param {AcquisitionOptions} options Independent source, transport and storage failure switches.
 * @returns {Promise<AcquisitionHarness>} Production exports plus observable boundary effects; never contacts Apple.
 */
async function acquisitionHarness(options = {}) {
    const state = {requests: [], files: options.existing || new Map(), reads: 0, writes: 0, inFlight: 0, maximumInFlight: 0, removed: [], locks: new Set()};
    const directory = {
        entries: async function* () { for (const [name] of state.files) yield [name, {kind: 'file', getFile: async () => state.files.get(name)}]; },
        removeEntry: async name => {
            if (state.inFlight) throw new Error('Cleanup raced an admitted disk write.');
            state.removed.push(name);
            if (!state.files.delete(name)) { const error = new Error('missing'); error.name = 'NotFoundError'; throw error; }
        },
        getFileHandle: async name => ({getFile: async () => state.files.get(name), createWritable: async () => {
            const parts = [];
            return {write: async bytes => {
                state.inFlight++; state.maximumInFlight = Math.max(state.maximumInFlight, state.inFlight);
                try { await options.beforeWrite?.(); parts.push(bytes.slice()); state.writes++; }
                finally { state.inFlight--; }
            }, close: async () => { state.files.set(name, new File(parts, name)); }, abort: async () => { parts.length = 0; }};
        }}),
    };
    const locks = {request: async (name, settingsOrCallback, callback) => {
        const action = callback || settingsOrCallback;
        if (state.locks.has(name)) return action(null);
        state.locks.add(name);
        try { return await action({name}); } finally { state.locks.delete(name); }
    }};
    const zipChunks = options.chunks || [new Uint8Array([0x50, 0x4b, 3, 4]), new Uint8Array(2 * 1024 * 1024)];
    let queries = 0;
    const fetch = async (url, init) => {
        assert.equal(init.credentials, 'omit');
        assert.equal(init.referrerPolicy, 'no-referrer');
        assert.equal(init.redirect, 'error');
        state.requests.push({url, init});
        if (options.networkFailure) throw new TypeError('Untrusted upstream diagnostic must never reach the UI.');
        if (url.includes('/records/resolve')) {
            if (options.expired) return new Response(JSON.stringify({serverErrorCode: 'NOT_FOUND', reason: 'private upstream diagnostic'}));
            const item = {databaseScope: 'SHARED', zoneID: {zoneName: 'synthetic-zone', ownerRecordName: 'synthetic-owner', zoneType: 'REGULAR_CUSTOM_ZONE'}, share: {recordName: 'synthetic-share'}, requireAppleLogin: options.privateSource || false, anonymousPublicAccess: {token: 'synthetic-anonymous-token', databasePartition: options.foreignPartition ? 'https://127.0.0.1' : 'https://p44-ckdatabasews.icloud.com:443'}};
            return new Response(JSON.stringify({results: [item], ...(options.oversizedMetadata ? {extra: 'x'.repeat(4 * 1024 * 1024)} : {})}));
        }
        if (url.includes('/records/query')) {
            queries++;
            const records = [{recordName: 'asset-' + queries, recordType: 'CPLAsset'}, {recordName: 'master-' + queries, recordType: 'CPLMaster'}];
            return new Response(JSON.stringify({records, ...(queries === 1 || options.repeatingMarker ? {continuationMarker: 'next-page'} : {})}), {headers: {'X-CloudKit-PublicAccess-AuthToken': 'refreshed-token-' + queries}});
        }
        if (url.includes('/zip/prepare')) return new Response(JSON.stringify({downloadURL: options.foreignArchive ? 'https://evil.test/archive' : 'https://cvws-h2.icloud-content.com/synthetic.zip?token=fixture'}));
        assert.equal(state.inFlight, 0, 'The next source read cannot overtake an admitted disk write.');
        const response = {ok: true, url, headers: new Headers(options.declaredLength === null ? {} : {'Content-Length': String(options.declaredLength ?? zipChunks.reduce((sum, chunk) => sum + chunk.byteLength, 0))}), body: {getReader: () => {
            let index = 0;
            return {read: async () => {
                assert.equal(state.inFlight, 0, 'Backpressure waits for each OPFS write before reading again.');
                if (init.signal.aborted) throw new DOMException('Aborted', 'AbortError');
                state.reads++;
                return index < zipChunks.length ? {value: zipChunks[index++], done: false} : {done: true};
            }, cancel: async () => {}, releaseLock: () => {}};
        }}, blob: () => { throw new Error('Whole-archive buffering is forbidden.'); }, arrayBuffer: () => { throw new Error('Whole-archive buffering is forbidden.'); }};
        return response;
    };
    const context = vm.createContext({URL, URLSearchParams, AbortController, TypeError, DOMException, Response, Headers, TextDecoder, Uint8Array, setTimeout, clearTimeout,
        crypto: {randomUUID: () => '00000000-0000-4000-8000-000000000001'}, window: {isSecureContext: !options.insecure}, navigator: {locks, storage: {estimate: async () => ({quota: options.quota ?? 100 * 1024 * 1024, usage: 0}), getDirectory: options.diskUnsupported ? undefined : async () => ({getDirectoryHandle: async () => directory})}}, fetch, i18n: key => key});
    const source = await readFile(new URL('../../public/assets/gallery-modules/admin-icloud-download.js', import.meta.url), 'utf8');
    vm.runInContext(source.replace(/^import .*;\s*$/gm, '').replace(/^export /gm, '') + '\nthis.api={prepareIcloudExport,stageIcloudZip};', context);
    return {api: context.api, state};
}

const source = {type: 'shared-album', url: 'https://photos.icloud.com/shared/album/SyntheticToken_0123456789/'};
const progress = [];
const normal = await acquisitionHarness();
const archive = await normal.api.stageIcloudZip(source, new AbortController().signal, event => progress.push(event));
assert.equal(archive.assetCount, 2);
assert.equal(normal.state.maximumInFlight, 1);
assert.equal(normal.state.writes, 3, 'A two-MiB source chunk is split into awaited one-MiB writes');
assert.equal(archive.file.size, 2 * 1024 * 1024 + 4);
assert.equal(normal.state.files.size, 1);
assert.equal(normal.state.locks.size, 1, 'The disk source stays locked until explicit cleanup');
const queryRequests = normal.state.requests.filter(item => item.url.includes('/records/query'));
assert.equal(new URL(queryRequests[1].url).searchParams.get('publicAccessAuthToken'), 'refreshed-token-1');
const zipRequest = normal.state.requests.find(item => item.url.includes('/zip/prepare'));
const zipBody = JSON.parse(zipRequest.init.body);
assert.equal(zipBody.shareName, 'synthetic-share');
assert.deepEqual(zipBody.includeRecords, ['asset-1', 'asset-2']);
assert.deepEqual(zipBody.pluginFields.codecs.value, ['H.264']);
assert.ok(zipBody.pluginFields.itemTypes.value.includes('public.jpeg'));
assert.equal(zipBody.pluginFields.originalsOnly, undefined);
assert.ok(normal.state.requests.every(item => !item.url.includes('gallery.example') && item.init.headers?.Authorization === undefined));
await archive.cleanup(); await archive.cleanup();
assert.equal(normal.state.files.size, 0);
assert.equal(normal.state.locks.size, 0);
assert.equal(progress.at(-1).loaded, archive.file.size);

for (const type of ['photos-link', 'shared-album']) {
    const product = await acquisitionHarness();
    await product.api.prepareIcloudExport({type, url: type === 'photos-link' ? 'https://www.icloud.com/photos/#SyntheticToken_0123456789/' : source.url}, new AbortController().signal, () => {});
    const request = product.state.requests.find(item => item.url.includes('/records/query'));
    const recordType = JSON.parse(request.init.body).query.recordType;
    assert.equal(recordType, type === 'photos-link' ? 'CPLAssetAndMasterByAssetDateWithoutHiddenOrDeleted' : 'CPLAssetAndMasterByAddedDate');
    const resolveRequest = product.state.requests[0];
    assert.equal(JSON.parse(resolveRequest.init.body).shortGUIDs[0].value, 'SyntheticToken_0123456789');
    assert.equal(resolveRequest.init.headers['Content-Type'], 'text/plain', 'The observed simple CORS request must not acquire a JSON preflight dependency');
}
for (const [options, failure] of [[{expired: true}, /source_unavailable/], [{privateSource: true}, /source_unavailable/], [{foreignPartition: true}, /protocol_failed/], [{foreignArchive: true}, /protocol_failed/], [{networkFailure: true}, /source_network_failed/], [{repeatingMarker: true}, /protocol_failed/], [{oversizedMetadata: true}, /protocol_failed/], [{quota: 1}, /storage_failed/], [{declaredLength: 100}, /zip_size/], [{declaredLength: 3 * 1024 * 1024}, /download_incomplete/]]) {
    const refused = await acquisitionHarness(options);
    await assert.rejects(() => refused.api.stageIcloudZip(source, new AbortController().signal, () => {}), failure);
    assert.equal(refused.state.files.size, 0);
    assert.equal(refused.state.locks.size, 0);
}
const unknown = await acquisitionHarness({declaredLength: null});
for (const options of [{diskUnsupported: true}, {insecure: true}]) {
    const unavailable = await acquisitionHarness(options);
    await assert.rejects(() => unavailable.api.stageIcloudZip(source, new AbortController().signal, () => {}), /disk_required/);
    assert.equal(unavailable.state.requests.length, 0, 'An unsupported/insecure browser refuses automatic acquisition before any Apple request');
}
const unknownProgress = [];
const unknownArchive = await unknown.api.stageIcloudZip(source, new AbortController().signal, event => unknownProgress.push(event));
assert.ok(unknownProgress.filter(event => event.phase === 'downloading').every(event => event.total === null));
await unknownArchive.cleanup();

const cancelled = new AbortController();
const cancellationChunk = new Uint8Array(2 * 1024 * 1024 + 4);
cancellationChunk.set([0x50, 0x4b, 3, 4]);
const cancellation = await acquisitionHarness({chunks: [cancellationChunk], beforeWrite: async () => { cancelled.abort(); await Promise.resolve(); }});
await assert.rejects(() => cancellation.api.stageIcloudZip(source, cancelled.signal, () => {}), /cancelled/);
assert.equal(cancellation.state.inFlight, 0, 'Cleanup settles the admitted write');
assert.equal(cancellation.state.writes, 1, 'Cancellation refuses another disk write after the admitted slice settles');
assert.equal(cancellation.state.files.size, 0);
assert.equal(cancellation.state.locks.size, 0);

const oldName = 'import-1-deadbeef.zip';
const activeName = 'import-2-beefdead.zip';
const unrelatedName = 'unrelated-user-file.zip';
const old = new Map([[oldName, new File(['old'], oldName, {lastModified: 0})], [activeName, new File(['active'], activeName, {lastModified: 0})], [unrelatedName, new File(['keep'], unrelatedName, {lastModified: 0})]]);
const orphan = await acquisitionHarness({existing: old});
orphan.state.locks.add('php-gallery:icloud:' + activeName);
const orphanArchive = await orphan.api.stageIcloudZip(source, new AbortController().signal, () => {});
assert.equal(orphan.state.files.has(oldName), false);
assert.equal(orphan.state.files.has(activeName), true);
assert.equal(orphan.state.files.has(unrelatedName), true);
await orphanArchive.cleanup();
console.log('Anonymous iCloud protocol, refreshed tokens, OPFS backpressure/cleanup, quota, abort and source bounds passed.');
