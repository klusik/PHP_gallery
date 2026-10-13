/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/browser_upload_zip_worker/icloud_upload.mjs
 * Module Type: Regression Test
 * Purpose: Exercise assisted imports through the real ordinary uploader with controlled dependencies.
 * Responsibilities:
 *   - Preserve prepared replay identities after lost acknowledgments and cancellation.
 *   - Refuse quota failures before persistence and verify worker/storage cleanup boundaries.
 *   - Propagate every failure to the registered browser_upload_zip_worker_test.mjs owner.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

/**
 * Describe production orchestration with controlled deterministic boundary adapters.
 * @typedef {Object} UploadHarness
 * @property {import('node:vm').Context} context Isolated uploader scope, including the actual transaction wrapper.
 * @property {{quota:boolean,loseAck:boolean,cancelOnUpload:AbortController|null,inserts:Set<string>,requests:string[],abortCount:number,cleaned:number}} state Observed failure switches and server replay identities.
 * @property {function(HTMLFormElement,HTMLElement,{prepared:import('../../public/assets/gallery-modules/admin-browser-upload.js').PreparedUploadAttempt|null,unresolved:boolean},AbortSignal|null):Promise<import('../../public/assets/gallery-modules/admin-side-panel.js').AdminPanelResponse>} upload Normal production upload coordinator.
 */
/**
 * Load the normal production uploader with controlled image, storage and server adapters.
 * @returns {Promise<UploadHarness>} Production coordinator and observable side-effect adapters.
 */
async function harness() {
    const source = await readFile(new URL('../../public/assets/gallery-modules/admin-browser-upload.js', import.meta.url), 'utf8');
    const state = {quota: false, loseAck: false, cancelOnUpload: null, inserts: new Set(), requests: [], abortCount: 0, cleaned: 0};
    const files = [new File(['one'], '01.png'), new File(['two'], '02.png')];
    const context = vm.createContext({URL, Blob, File, AbortController, window: {location: {href: 'https://gallery.example/index.php?page=admin_upload', origin: 'https://gallery.example'}},
        state, files, appendUploadProgressLog: () => {}, updateBasicProgress: () => {}, updateUploadProgressMetrics: () => {}, assertAdminOperationCurrent: () => {},
        i18n: (key, fallback, params = {}) => Object.entries(params).reduce((text, [name, value]) => text.replaceAll(`{${name}}`, String(value)), fallback || key),
    });
    const transformed = source.replace(/^import .*;\s*$/gm, '').replace(/^export /gm, '').replaceAll('import.meta.url', "'https://gallery.example/assets/admin-browser-upload.js'");
    vm.runInContext(transformed + '\nthis.productionPool=processFilesWithWorkerPool;\n' + `
        browserUploadConfig = () => ({enabled:true,endpoint:'/upload',thumbnailSizes:[],thumbnailFormats:[],workerCount:1,uploadLimitBytes:1000});
        selectedBrowserUploadFiles = () => files;
        selectedBrowserUploadPdfFiles = () => [];
        selectedBrowserGalleryId = () => 41;
        browserUploadCapability = () => ({ok:true});
        browserUploadSessionId = () => 'original-session';
        browserProgressMetrics = () => ({});
        formatFileSize = value => String(value);
        expandBrowserUploadArchives = async () => ({files,skipped:0});
        processFilesWithWorkerPool = async (items, config, onItem) => {
            for (let index=0;index<items.length;index++) {
                throwIfUploadStreamAborted(config.signal);
                await onItem({sourceIndex:index,originalName:items[index].name,originalSize:items[index].size},index+1,items.length);
            }
        };
        createBrowserBatcher = async () => {
            if (state.quota) { const error=new Error('quota'); error.name='QuotaExceededError'; throw error; }
            const batches=files.map((file,index)=>({index,blob:new Blob([file]),itemCount:1,cleanup:async()=>{state.cleaned++;}}));
            return {batches,addItem:async item=>({itemBytes:item.originalSize}),finish:async()=>batches,
                cleanupPrepared:async()=>{},abort:async()=>{state.abortCount++;}};
        };
        uploadPreparedBatchWithRetry = async (_form,_config,_gallery,session,batch,index,_total,_thumbs,_progress,options) => {
            throwIfUploadStreamAborted(options.signal);
            const identity=session+':'+index;
            state.requests.push(identity);
            state.inserts.add(identity);
            if (state.cancelOnUpload) { state.cancelOnUpload.abort(); throwIfUploadStreamAborted(options.signal); }
            if (state.loseAck) { state.loseAck=false; throw new Error('lost acknowledgment'); }
            return {ok:true,gallery_id:41,gallery_ids:[41],image_ids:[index+1],uploaded:1,scanned:0,thumbnails:0,upload_events:[],
                mutation:{type:'image.upload',entity:'image',action:'create',entity_ids:[index+1]},contexts:[],fallback:{}};
        };
        this.upload=runBrowserGalleryUpload;
    `, context);
    return {context, state, upload: context.upload};
}

const form = {querySelector: () => null, dataset: {}};
const progress = {};
const operation = {prepared: null, unresolved: false};
const replay = await harness();
replay.state.loseAck = true;
await assert.rejects(() => replay.upload(form, progress, operation), /lost acknowledgment/);
assert.ok(operation.prepared);
assert.equal(operation.prepared.uploadSessionId, 'original-session');
assert.equal(replay.state.cleaned, 0, 'Unknown acknowledgments never clean the retained ZIP');
const firstBatch = operation.prepared.batches[0].blob;
const result = await replay.upload(form, progress, operation);
assert.equal(result.uploaded, 2);
assert.equal(operation.prepared.batches[0].blob, firstBatch, 'Retry retains the exact ZIP bytes');
assert.equal(replay.state.inserts.size, 2, 'Server adapter sees only two distinct replay identities');
assert.deepEqual(replay.state.requests, ['original-session:0', 'original-session:0', 'original-session:1']);

const cancelled = await harness();
const cancelOperation = {prepared: null, unresolved: false};
const abort = new AbortController();
cancelled.state.cancelOnUpload = abort;
await assert.rejects(() => cancelled.upload(form, progress, cancelOperation, abort.signal), /cancelled/);
assert.ok(cancelOperation.prepared?.serverSideStarted);
assert.equal(cancelled.state.cleaned, 0);
cancelled.state.cancelOnUpload = null;
await cancelled.upload(form, progress, cancelOperation, new AbortController().signal);
assert.equal(cancelled.state.inserts.size, 2, 'Retry reconciles the uncertain cancelled batch');

const quota = await harness();
quota.state.quota = true;
const quotaOperation = {prepared: null, unresolved: false};
await assert.rejects(() => quota.upload(form, progress, quotaOperation), /storage_failed/);
assert.equal(quota.state.requests.length, 0);
assert.equal(quotaOperation.prepared, null);
const early = new AbortController(); early.abort();
await assert.rejects(() => quota.upload(form, progress, quotaOperation, early.signal), /cancelled/);
assert.equal(quota.state.requests.length, 0);

// Check the real transaction wrapper: request success is insufficient before commit.
const stored = await harness();
const listeners = new Map();
const requestListeners = new Map();
const request = {result: 'key', addEventListener: (name, handler) => requestListeners.set(name, handler)};
const transaction = {objectStore: () => ({}), addEventListener: (name, handler) => listeners.set(name, handler)};
const database = {transaction: () => transaction};
stored.context.database = database; stored.context.request = request;
let committed = false;
const pending = vm.runInContext('indexedDbRequest(database,"readwrite",()=>request)', stored.context).then(() => {committed = true;});
requestListeners.get('success')?.();
await Promise.resolve();
assert.equal(committed, false);
listeners.get('complete')();
await pending;
assert.equal(committed, true);
const failed = vm.runInContext('indexedDbRequest(database,"readwrite",()=>request)', stored.context);
transaction.error = new Error('quota abort');
listeners.get('abort')();
await assert.rejects(() => failed, /quota abort/);

// Exercise the actual packaging worker callback and cancellation/write barrier.
let activeWorker;
stored.context.Worker = function () {
    const callbacks = new Map();
    const worker = {callbacks, terminated: false,
        addEventListener: (name, callback) => callbacks.set(name, callback),
        postMessage: () => {}, terminate: () => {worker.terminated = true;}};
    activeWorker = worker;
    return worker;
};
const packed = vm.runInContext('createStoreOnlyZipInWorker([])', stored.context);
const zip = new Blob(['synthetic package']);
activeWorker.callbacks.get('message')({data: {ok: true, zipBlob: zip}});
assert.equal(await packed, zip);
assert.equal(activeWorker.terminated, true);
const poolAbort = new AbortController();
let releaseWrite;
let writesStarted = 0;
const write = new Promise(resolve => {releaseWrite = resolve;});
const pooled = stored.context.productionPool([new File(['fixture'], 'a.png')], {workerCount: 1, thumbnailSizes: [], thumbnailFormats: [], jpegQuality: 0.8, webpQuality: 0.8, signal: poolAbort.signal}, async () => {writesStarted++; await write;});
activeWorker.callbacks.get('message')({data: {ok: true, item: {originalSize: 7}}});
await Promise.resolve();
poolAbort.abort();
activeWorker.callbacks.get('message')({data: {ok: true, item: {originalSize: 7}}});
let settled = false;
const poolFailure = assert.rejects(() => pooled, /cancelled/).then(() => {settled = true;});
await Promise.resolve();
assert.equal(settled, false, 'Cancellation waits for an already admitted storage write');
assert.equal(writesStarted, 1, 'Cancelled queued worker replies do not admit more writes');
releaseWrite();
await poolFailure;
console.log('Ordinary uploader replay, cancelled ACK reconciliation, quota and transaction durability passed.');
