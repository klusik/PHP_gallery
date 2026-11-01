/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_consistency_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Verify browser-only repair orchestration and exact worker variants.
 * Responsibilities: Exercise passive reconciliation, partial/lost replies and
 *     real production worker control with isolated decoder/encoder seams.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL(
    '../public/assets/gallery-modules/gallery-consistency.js', import.meta.url,
), 'utf8');
const start = source.indexOf('export async function consistencyRunBrowserTask(');
const end = source.indexOf('export function setupGalleryConsistency', start);
assert.ok(start >= 0 && end > start, 'Production browser handshake must exist.');

for (const scenario of ['normal', 'already-published', 'partial', 'worker-failure']) {
    const calls = [];
    const fields = new Map();
    const form = {querySelector: selector => {
        if (!fields.has(selector)) fields.set(selector, {value:''});
        return fields.get(selector);
    }};
    const initial = {status:'browser', token:'owned-ticket', sequence:7};
    const replies = scenario === 'already-published'
        ? [{...initial, status:'running', sequence:8}]
        : [initial, {...initial, status:scenario === 'partial' ? 'browser' : 'running'}];
    const context = vm.createContext({
        URLSearchParams, Error,
        consistencyRequest: async (_root, body) => {
            calls.push(body.get('action'));
            assert.equal(body.get('token'), 'owned-ticket');
            assert.equal(body.get('sequence'), '7');
            return replies.shift();
        },
        runBrowserThumbnailRebuild: async (receivedForm, _progress, options) => {
            calls.push('worker');
            assert.equal(receivedForm, form);
            assert.equal(options.singlePass, true);
            assert.equal(fields.get('[name="consistency_token"]').value, 'owned-ticket');
            assert.equal(fields.get('[name="consistency_sequence"]').value, '7');
            if (scenario === 'worker-failure') throw new Error('Decoder refused source');
        },
    });
    vm.runInContext(source.slice(start, end).replace('export async function',
        'async function'), context);
    const receipts = [];
    const operation = context.consistencyRunBrowserTask({}, initial, form, {},
        receipt => receipts.push(receipt.status));
    if (scenario === 'worker-failure') {
        await assert.rejects(operation, /Decoder refused source/);
        assert.deepEqual(calls, ['browser_verify', 'worker']);
    } else if (scenario === 'partial') {
        await assert.rejects(operation, /consistency_browser_incomplete/);
        assert.deepEqual(calls, ['browser_verify', 'worker', 'browser_verify']);
    } else {
        assert.equal((await operation).status, 'running');
        assert.deepEqual(calls, scenario === 'already-published'
            ? ['browser_verify'] : ['browser_verify', 'worker', 'browser_verify']);
    }
    assert.equal(receipts[0], scenario === 'already-published' ? 'running' : 'browser');
}

const workerSource = await readFile(new URL(
    '../public/assets/gallery-modules/browser-image-worker.js', import.meta.url,
), 'utf8');
for (const restricted of [true, false]) {
    let handler;
    let finish;
    let closed = false;
    const encodings = [];
    const result = new Promise(resolve => { finish = resolve; });
    /** Model the native canvas boundary while retaining production pair selection. */
    class FixtureCanvas {
        /** Retain production-requested output geometry.
         * @param {number} width Output width in pixels.
         * @param {number} height Output height in pixels.
         * @returns {void} Initializes the fixture canvas.
         */
        constructor(width, height) { this.width = width; this.height = height; }
        /** Supply only the actual drawing boundary used by the rebuild worker.
         * @param {string} kind Requested context identity.
         * @returns {{drawImage:function():void}} Inert 2D drawing seam.
         */
        getContext(kind) { assert.equal(kind, '2d'); return {drawImage:()=>{}}; }
        /** Record each requested encoder and return a typed fake image Blob.
         * @param {{type:string,quality:number}} options Encoder configuration.
         * @returns {Promise<Blob>} Encoded fixture bytes for protocol inspection.
         */
        async convertToBlob(options) {
            encodings.push({width:this.width, type:options.type});
            return new Blob(['encoded'], {type:options.type});
        }
    }
    const worker = {
        OffscreenCanvas:FixtureCanvas,
        createImageBitmap:async () => ({width:1600,height:900,
            close:()=>{closed=true;}}),
        addEventListener:(_kind, callback)=>{handler=callback;},
        postMessage:message=>finish(message),
    };
    const context = vm.createContext({self:worker, Blob, OffscreenCanvas:FixtureCanvas,
        TextDecoder, TextEncoder, DataView, Uint8Array, ArrayBuffer});
    vm.runInContext(workerSource, context);
    // Three configured size vectors and two formats distinguish an exact pair
    // selection from the legacy full Cartesian product of all six variants.
    handler({data:{action:'prepareRebuildImage', id:1, imageId:9,
        filename:'source.jpg', entryPath:'originals/9/source.jpg',
        blob:new Blob(['source']), sizes:[300,600,800], formats:['jpg','webp'],
        targetVariants:restricted ? ['300:webp','600:jpg'] : [],
        expectedVariants:restricted ? 2 : 6}});
    const reply = await result;
    assert.equal(reply.ok, true);
    assert.equal(reply.item.variants.length, restricted ? 2 : 6);
    assert.equal(closed, true, 'The decoded bitmap must always be released.');
    if (restricted) assert.deepEqual(encodings,
        [{width:300,type:'image/webp'},{width:600,type:'image/jpeg'}]);
}
// Exercise the production receipt renderer across batch completion and paging.
const renderStart = source.indexOf('        function renderReceipt(receipt)');
const renderEnd = source.indexOf('\n        /**', renderStart);
assert.ok(renderStart >= 0 && renderEnd > renderStart);
const inputs = ['g1', 'g2'].map(key => ({dataset:{consistencyGroup:key},checked:true}));
const closeSnapshots = [];
const renderer = vm.createContext({
    state:{mode:'repair',status:'running',sequence:9},
    selected:new Set(['g1']), proposalMoves:[],
    labels:{report_scope:'Scope',scope_files:'Files',repair_done:'Repaired:',
        variants_confirmed:'Variants',failed:'Failed'},
    reportScope:{}, browserNotice:{}, browserProgress:{}, progress:{},
    bar:{}, count:{}, status:{}, repairToolbar:{}, exportLink:{}, resume:{},
    results:{innerHTML:'old rows',querySelectorAll:()=>inputs},
    updateProposals:()=>{}, closeHelp:()=>closeSnapshots.push(renderer.results.innerHTML),
});
vm.runInContext(source.slice(renderStart, renderEnd), renderer);
const completed = {mode:'repair',status:'complete',sequence:10,token:'same-report',
    scope:'files',processed:1,total:1,repaired:1,failed:0,confirmed_variants:6,
    repairable_groups:1,browser_groups:[],moves:[],html:'remaining gallery'};
renderer.renderReceipt(completed);
assert.equal(renderer.repairToolbar.hidden, false,
    'A completed subset must immediately enable the remaining repair controls.');
assert.equal(renderer.selected.size, 0, 'A completed batch must clear its selection.');
assert.match(renderer.status.textContent, /Variants: 6/);
renderer.selected.add('g2');
renderer.renderReceipt({...completed,html:'another result page'});
assert.equal(renderer.selected.has('g2'), true,
    'Paging the same completed receipt must preserve a newly selected subset.');
renderer.renderReceipt({...completed,sequence:12,repairable_groups:0,html:'no remaining rows'});
assert.equal(renderer.repairToolbar.hidden, true);
assert.equal(renderer.results.innerHTML, 'no remaining rows');
assert.equal(renderer.selected.size, 0);
assert.deepEqual(closeSnapshots,['old rows','remaining gallery','another result page'],
    'Each result replacement must restore portalled help before removing its trigger.');
console.log('Consistency browser reconciliation, remaining selection and exact variants: PASS');

// Exercise the production delegated help owner with narrow viewport geometry.
const helpStart = source.indexOf('function setupConsistencyHelp(');
const helpEnd = source.indexOf('/**\n * Reconcile one pending browser task', helpStart);
assert.ok(helpStart >= 0 && helpEnd > helpStart);
const handlers = () => ({events:new Map(),addEventListener:(kind,fn)=>{}});
const rootHelp = handlers();
const documentHelp = handlers();
const windowHelp = handlers();
for (const owner of [rootHelp, documentHelp, windowHelp]) {
    owner.addEventListener = (kind, fn) => {
        const list = owner.events.get(kind) || [];
        list.push(fn);
        owner.events.set(kind, list);
    };
}
const timers = new Set();
const body = {append:node=>{node.parent=body;}};
const trigger = {
    attrs:new Map(),setAttribute:(key,value)=>trigger.attrs.set(key,value),
    getBoundingClientRect:()=>({left:350,top:792,bottom:820}),
    focus:()=>{documentHelp.focused=trigger;},
    closest:selector=>selector.includes('> summary') ? trigger : disclosure,
};
const helpPanel = {
    parent:null,offsetWidth:350,offsetHeight:230,style:{},classes:new Set(),
    contains:node=>node===helpPanel,
    removeAttribute:key=>{if(key==='style')helpPanel.style={};},
    classList:{add:value=>helpPanel.classes.add(value),
        remove:value=>helpPanel.classes.delete(value)},
};
const disclosure = {
    open:false,append:node=>{node.parent=disclosure;},
    querySelector:selector=>selector==='summary' ? trigger : helpPanel,
    contains:node=>node===trigger || node===disclosure
        || (node===helpPanel && helpPanel.parent===disclosure),
};
helpPanel.parent=disclosure;
rootHelp.contains = node=>node===disclosure;
documentHelp.body=body;
// 390 by 844 is an explicit narrow viewport boundary vector.
documentHelp.documentElement={clientWidth:390,clientHeight:844};
const helpContext = vm.createContext({
    document:documentHelp,window:windowHelp,rootHelp,
    CONSISTENCY_HELP_EDGE:Number(source.match(/const CONSISTENCY_HELP_EDGE = ([0-9]+)/)[1]),
    CONSISTENCY_HELP_LEAVE_MS:Number(source.match(/const CONSISTENCY_HELP_LEAVE_MS = ([0-9]+)/)[1]),
    setTimeout:fn=>{timers.add(fn);return fn;},
    clearTimeout:fn=>timers.delete(fn),
});
vm.runInContext(source.slice(helpStart,helpEnd),helpContext);
const closeHelp = helpContext.setupConsistencyHelp(rootHelp);
const emit = (owner, kind, target, extra={}) => {
    for(const fn of owner.events.get(kind)||[]) fn({target,preventDefault:()=>{},...extra});
};
emit(rootHelp,'focusin',trigger);
assert.equal(disclosure.open,true,'Focus must explain a finding without a click.');
assert.equal(helpPanel.parent,body,'Help must escape filtered layout containers.');
assert.ok(Number.parseFloat(helpPanel.style.left) + helpPanel.offsetWidth <= 390);
assert.ok(Number.parseFloat(helpPanel.style.top) + helpPanel.offsetHeight <= 844);
emit(rootHelp,'click',trigger);
emit(documentHelp,'pointerover',{});
assert.equal(timers.size,0,'Explicit activation must pin help for reading.');
emit(documentHelp,'keydown',trigger,{key:'Escape'});
assert.equal(disclosure.open,false);
assert.equal(helpPanel.parent,disclosure);
assert.equal(documentHelp.focused,trigger,'Escape must return focus to its trigger.');
emit(rootHelp,'pointerover',trigger);
emit(documentHelp,'pointerover',{});
assert.equal(timers.size,1,'Transient hover help must close after leaving.');
for(const callback of [...timers]) callback();
assert.equal(disclosure.open,false);
emit(rootHelp,'click',trigger);
emit(documentHelp,'click',{});
assert.equal(disclosure.open,false,'Outside click must dismiss pinned help.');
emit(rootHelp,'focusin',trigger);
closeHelp();
assert.equal(helpPanel.parent,disclosure,'Result replacement must restore help first.');
assert.equal(helpPanel.classes.size,0,'Closed help must not leak a fixed panel.');
console.log('Consistency delegated hover, focus, pin and dismissal: PASS');
