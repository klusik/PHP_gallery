/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/maintenance_center_controls_test.mjs
 * Module Type: Regression Test
 * Purpose: Exercise serialized maintenance stop controls during active requests.
 * Responsibilities: Verify queued pause/cancel, cancellation precedence and
 *     consumption of the durable cancellation flag without another work slice.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL(
    '../public/assets/gallery-modules/admin-maintenance-center.js',
    import.meta.url,
), 'utf8');
const start = source.indexOf('    async function continueLoop(kind)');
const end = source.indexOf("    document.addEventListener('click'", start);
assert.ok(start >= 0 && end > start, 'Production control owners must exist.');
const declarations = source.slice(start, end);

/**
 * Hold real control requests until the fixture releases their owned response.
 * @param {string} status Initial persisted analyzing or running status.
 * @param {string} terminal Expected paused or cancelled terminal presentation.
 * @returns {{context: {job:{id:number,status:string},requestActive:boolean,
 *     autoContinue:boolean,pendingControl:string,
 *     continueLoop:function(string):Promise<void>,
 *     handleAction:function(string):Promise<void>,
 *     request:function(string,{job_id:number}):Promise<{id:number,status:string}>},
 *     calls: Array<string>, pending:
 *     Array<{resolve: function({id:number,status:string}):void,
 *     reject:function(Error):void}>, settled: Promise<void>}} Isolated production
 *     controls, ordered requests and an awaited terminal-render receipt.
 */
function controlFixture(status, terminal) {
    const calls = [];
    const pending = [];
    let complete;
    const settled = new Promise(resolve => { complete = resolve; });
    const context = vm.createContext({
        job: {id: 4, status},
        requestActive: false,
        autoContinue: true,
        pendingControl: '',
        strings: {},
        endpoints: {
            analyzeStep: 'analyze-step', executeStep: 'execute-step',
            pause: 'pause', cancel: 'cancel',
        },
        request: (url, fields) => {
            assert.equal(fields.job_id, 4);
            assert.equal(pending.length, 0, 'Requests must remain serial.');
            calls.push(url);
            return new Promise((resolve, reject) => {
                pending.push({resolve, reject});
            });
        },
        render: () => {
            if (context.job.status === terminal) complete();
        },
        renderActions: () => {},
        setBrowserError: () => {},
        // Queued stop controls must prevent another delay or work admission.
        window: {setTimeout: () => assert.fail('Unexpected continuation timer')},
    });
    vm.runInContext(declarations, context);
    return {context, calls, pending, settled};
}

const pause = controlFixture('running', 'paused');
const pauseLoop = pause.context.continueLoop('execution');
await pause.context.handleAction('pause');
assert.equal(pause.context.autoContinue, false);
assert.deepEqual(pause.calls, ['execute-step']);
pause.pending.shift().resolve({id: 4, status: 'running'});
await pauseLoop;
assert.deepEqual(pause.calls, ['execute-step', 'pause']);
pause.pending.shift().resolve({id: 4, status: 'paused'});
await pause.settled;
assert.equal(pause.context.requestActive, false);

for (const [status, kind, step] of [
    ['running', 'execution', 'execute-step'],
    ['analyzing', 'analysis', 'analyze-step'],
]) {
    const cancel = controlFixture(status, 'cancelled');
    const loop = cancel.context.continueLoop(kind);
    await cancel.context.handleAction('pause');
    await cancel.context.handleAction('cancel');
    await cancel.context.handleAction('pause');
    assert.equal(cancel.context.pendingControl, 'cancel');
    cancel.pending.shift().resolve({id: 4, status});
    await loop;
    assert.deepEqual(cancel.calls, [step, 'cancel']);
    cancel.pending.shift().resolve({id: 4, status});
    // Await the actual next request, without guessing microtask counts.
    const consumed = new Promise(resolve => {
        const original = cancel.context.request;
        cancel.context.request = (url, fields) => {
            const result = original(url, fields);
            resolve();
            return result;
        };
    });
    await consumed;
    assert.deepEqual(cancel.calls, [step, 'cancel', step]);
    cancel.pending.shift().resolve({id: 4, status: 'cancelled'});
    await cancel.settled;
    assert.equal(cancel.context.requestActive, false);
    assert.equal(cancel.context.pendingControl, '');
}

const interrupted = controlFixture('running', 'cancelled');
const interruptedLoop = interrupted.context.continueLoop('execution');
await interrupted.context.handleAction('cancel');
interrupted.pending.shift().reject(new Error('Fixture transport interrupted.'));
await interruptedLoop;
assert.deepEqual(interrupted.calls, ['execute-step', 'cancel']);
interrupted.pending.shift().resolve({id: 4, status: 'cancelled'});
await interrupted.settled;
assert.equal(interrupted.context.requestActive, false);

console.log('Maintenance pause/cancel serialization and durable cancellation: PASS');
