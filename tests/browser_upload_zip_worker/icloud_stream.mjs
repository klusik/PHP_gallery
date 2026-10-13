/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/browser_upload_zip_worker/icloud_stream.mjs
 * Module Type: Regression Scenarios
 * Purpose: Verify ordinary-image File streams through the actual existing batcher and acknowledgment coordinator.
 * Responsibilities:
 *   - Prove interleaved preparation/upload, exact lost-ACK replay and source cursor preservation.
 *   - Keep private new-target creation idempotent and retain the default strict PDF PNG path.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

/** Describe focused stream switches and observed side effects.
 * @typedef {{newGallery?:boolean,loseAck?:boolean,loseCreate?:boolean,wrongPng?:boolean}} StreamOptions
 */
/** Describe the existing stream coordinator with deterministic transport/storage adapters.
 * @typedef {{upload:typeof import('../../public/assets/gallery-modules/admin-browser-upload.js').uploadBrowserFileStream,state:{events:string[],requests:Array<{session:string,index:number,blob:Blob,endpoint:string,conversionToken:string}>,inserted:Set<string>,creations:Set<string>,createRequests:number,store:Map<string,Blob|{originalFile:File,originalSize:number,originalName:string,originalPath:string,variants:Array<{path:string,blob:Blob}>,sourceIndex:number}>,loseAck:boolean,loseCreate:boolean}}} StreamHarness
 */

/** Load the production stream/batcher while replacing only filesystem, codec and HTTP boundaries.
 * @param {StreamOptions} options Deliberate lost responses or wrong converter output.
 * @returns {Promise<StreamHarness>} Real stream coordinator and batcher with observable, synthetic transport effects.
 */
async function streamHarness(options = {}) {
    const state = {events: [], requests: [], inserted: new Set(), creations: new Set(), createRequests: 0, store: new Map(), loseAck: Boolean(options.loseAck), loseCreate: Boolean(options.loseCreate)};
    const config = {enabled: true, endpoint: '/prepared', thumbnailSizes: [], thumbnailFormats: [], workerCount: 1, uploadLimitBytes: 1024 * 1024, batchTargetBytes: 1024, maxItemsPerBatch: 1};
    const context = vm.createContext({URL, Blob, File, AbortController, Uint8Array, options, state, config, window: {location: {href: 'https://gallery.example/admin', origin: 'https://gallery.example'}},
        appendUploadProgressLog: () => {}, updateBasicProgress: () => {}, updateUploadProgressMetrics: () => {}, assertAdminOperationCurrent: () => {},
        i18nForElement: (element, key, fallback, parameters = {}) => {
            assert.ok(element === form || element === progress, 'Image-stream messages use their original form/progress Admin scope');
            return Object.entries(parameters).reduce((text, [name, value]) => text.replaceAll('{' + name + '}', String(value)), fallback || key);
        },
        i18n: (key, fallback, parameters = {}) => Object.entries(parameters).reduce((text, [name, value]) => text.replaceAll('{' + name + '}', String(value)), fallback || key)});
    const source = await readFile(new URL('../../public/assets/gallery-modules/admin-browser-upload.js', import.meta.url), 'utf8');
    vm.runInContext(source.replace(/^import .*;\s*$/gm, '').replace(/^export /gm, '').replaceAll('import.meta.url', "'https://gallery.example/assets/admin-browser-upload.js'") + `
        browserUploadConfig = () => config;
        selectedBrowserGalleryId = () => options.newGallery ? 0 : 41;
        browserUploadSessionId = () => 'stable-stream-session';
        browserProgressMetrics = () => '';
        appendUploadResultParams = value => value;
        openPreparedBatchStore = async () => ({put:async(id,value)=>state.store.set(id,value),get:async id=>state.store.get(id),delete:async id=>state.store.delete(id)});
        createStoreOnlyZipInWorker = async entries => new Blob(entries.map(entry => entry.blob));
        processFilesWithWorkerPool = async (files,settings,onItem) => {
            const file=files[0];
            state.events.push('prepare:'+settings.workerItemIdOffset);
            await onItem({sourceIndex:settings.workerItemIdOffset,originalFile:options.wrongPng?new File(['changed'],file.name,{type:file.type}):file,
                originalName:file.name,originalPath:'originals/'+file.name,originalDetectedFormat:'png',originalMime:file.type,originalSize:file.size,variants:[]},1,1);
        };
        createGalleryForBrowserUpload = async (_form,operation) => {
            state.createRequests++;
            state.creations.add(operation.key);
            state.events.push('create');
            if(state.loseCreate){state.loseCreate=false;throw new Error('lost create acknowledgment');}
            return {ok:true,created_gallery:true,gallery_id:41,gallery_ids:[41],uploaded:0,scanned:0,thumbnails:0,image_ids:[],contexts:[{type:'gallery',gallery_id:41}],panel:{refresh_url:'/panel/41'},fallback:{redirect_url:'/gallery/41'},mutation:{type:'gallery.create',entity:'gallery',action:'create',entity_ids:[41]}};
        };
        uploadPreparedBatchWithRetry = async (_form,_config,gallery,session,batch,index,_total,_thumbs,_progress,transport) => {
            throwIfUploadStreamAborted(transport.signal);
            if(gallery!==41)throw new Error('Wrong target');
            state.requests.push({session,index,blob:batch.blob,endpoint:transport.endpoint,conversionToken:transport.conversionToken});
            state.inserted.add(session+':'+index);
            state.events.push('upload:'+index);
            if(state.loseAck){state.loseAck=false;throw new Error('lost batch acknowledgment');}
            return {ok:true,gallery_id:41,gallery_ids:[41],uploaded:batch.itemCount,scanned:0,thumbnails:0,image_ids:[index+1],contexts:[],fallback:{},mutation:{type:'image.upload',entity:'image',action:'create',entity_ids:[index+1]}};
        };
        this.upload=uploadBrowserFileStream;
    `, context);
    return {upload: context.upload, state};
}

/** Construct an ordered, regenerable synthetic source starting at the retained upload cursor.
 * @param {string[]} events Observed reader/preparation/transport order.
 * @param {number} start Zero-based resume offset into two tiny genuine PNG Files.
 * @returns {{pageCount:number,[Symbol.asyncIterator]:function():AsyncIterator<File>}} Pull-driven source with no real user files.
 */
function imageStream(events, start = 0) {
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
    return {pageCount: 2, [Symbol.asyncIterator]: async function* () {
        for (let index = start; index < 2; index++) {
            events.push('read:' + index);
            yield new File([png], 'source-' + index + '.png', {type: 'image/png'});
        }
    }};
}

const form = {querySelector: selector => selector === '[name="upload_mode"]' ? {value: 'new'} : selector === '[name="visibility"]' ? {value: 'private'} : null};
const progress = {};
const normal = await streamHarness();
const normalOperation = {key: 'root-intent', prepared: null, unresolved: false};
const normalResult = await normal.upload(form, progress, normalOperation, imageStream(normal.state.events), {sourceKind: 'images'});
assert.equal(normalResult.uploaded, 2);
assert.ok(normal.state.events.indexOf('upload:0') < normal.state.events.indexOf('read:1'), 'A ready batch is acknowledged before pulling the next image');
assert.ok(normal.state.requests.every(request => request.endpoint === 'https://gallery.example/prepared' && request.conversionToken === ''));
assert.equal(normal.state.store.size, 0, 'Confirmed packages and prepared image items are removed');

const lost = await streamHarness({loseAck: true, newGallery: true});
const lostOperation = {key: 'stable-new-target-intent', prepared: null, unresolved: false};
await assert.rejects(() => lost.upload(form, progress, lostOperation, imageStream(lost.state.events), {sourceKind: 'images'}), /lost batch acknowledgment/);
assert.equal(lost.state.createRequests, 1, 'New private gallery is created only after a valid prepared batch');
assert.equal(lostOperation.prepared.nextSourceIndex, 1);
assert.equal(lost.state.events.includes('read:1'), false, 'Unknown acknowledgment stops source consumption');
const originalBatch = lostOperation.prepared.batches[0].blob;
const retryResult = await lost.upload(form, progress, lostOperation, imageStream(lost.state.events, 1), {sourceKind: 'images', startPage: 2});
assert.equal(retryResult.uploaded, 2);
assert.equal(lost.state.requests[1].blob, originalBatch);
assert.equal(lost.state.requests[0].session, lost.state.requests[1].session);
assert.equal(lost.state.inserted.size, 2, 'Three requests reconcile two distinct server batch identities');
assert.equal(lost.state.createRequests, 1, 'An acknowledged destination is not created again');
assert.equal(retryResult.created_gallery, true);
assert.equal(retryResult.mutation.type, 'gallery.create_with_upload');
assert.equal(retryResult.contexts[0].gallery_id, 41, 'Canonical creation contexts survive subsequent batch aggregation');
assert.equal(retryResult.panel.refresh_url, '/panel/41');

const lostCreate = await streamHarness({loseCreate: true, newGallery: true});
const createOperation = {key: 'stable-create-replay', prepared: null, unresolved: false};
await assert.rejects(() => lostCreate.upload(form, progress, createOperation, imageStream(lostCreate.state.events), {sourceKind: 'images'}), /lost create acknowledgment/);
await lostCreate.upload(form, progress, createOperation, imageStream(lostCreate.state.events, 1), {sourceKind: 'images', startPage: 2});
assert.equal(lostCreate.state.createRequests, 2);
assert.equal(lostCreate.state.creations.size, 1, 'Unknown creation acknowledgment reuses the original root intent');

const pdf = await streamHarness({wrongPng: true});
await assert.rejects(() => pdf.upload(form, progress, {key: 'pdf-default', prepared: null, unresolved: false}, imageStream(pdf.state.events)), /failed PNG preparation/);
assert.equal(pdf.state.requests.length, 0, 'Default PDF mode still refuses changed converter originals before upload');
console.log('Existing image File-stream interleaving, exact batch/create replay and default strict PDF PNG preservation passed.');
