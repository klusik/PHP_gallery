/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_visit_progress_test.mjs
 * Module Type: Regression Test
 * Purpose: Exercise direct visits, independent additions and concurrent resets.
 * Responsibilities: Run production graph/storage policy against explicit vectors.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const source = readFileSync(new URL(
    '../public/assets/gallery-modules/gallery-visit-progress.js', import.meta.url,
), 'utf8');
const {parseVisitHistory, mergeVisitHistory, observeVisitGraph,
    acknowledgeVisitNodes, computeVisitStates, markVisitBranchUnseen} = await import(
    `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`,
);

/**
 * Build an authorized fixture node without a media or tracking snapshot.
 * @param {string[]} children Navigable fixture identities.
 * @param {Record<string,number>} watermark Current compact content lanes.
 * @returns {{title:string,url:string,children:string[],watermark:Record<string,number>}}
 *     Public node consumed by the real production policy.
 */
function node(children = [], watermark = {}) {
    return {title: 'Fixture', url: '/gallery/fixture/', children, watermark};
}

const nodes = {
    'r:0': node(['g:1'], {'e:g:1': 1}),
    'g:1': node(['g:2', 's:1'], {'m:1:0': 1, 'e:g:2': 1, 'e:s:1': 1}),
    'g:2': node([], {'m:2:0': 1}),
    's:1': node([], {'m:2:0': 1}),
};
const history = parseVisitHistory(null);
observeVisitGraph(history, nodes, true);
assert.equal(Object.values(history.g).some(record => record.o), false,
    'Listing observations must never count as direct opens.');
assert.equal(computeVisitStates(nodes, history)['g:1'].progress, 'unseen');
acknowledgeVisitNodes(history, nodes, ['g:2'], true);
assert.equal(computeVisitStates(nodes, history)['g:1'].progress, 'partial');
assert.equal(history.g['g:1'].o, false, 'A child must not mark its parent opened.');
acknowledgeVisitNodes(history, nodes, ['g:1'], true);
assert.equal(computeVisitStates(nodes, history)['g:1'].progress, 'partial');
acknowledgeVisitNodes(history, nodes, ['s:1'], true);
assert.equal(computeVisitStates(nodes, history)['g:1'].progress, 'seen');
assert.equal(history.g['g:2'].o, true, 'Virtual visits retain independent source state.');

// New direct media does not undo Seen. Re-rendering cannot acknowledge it.
nodes['g:2'].watermark['m:2:0'] = 2;
observeVisitGraph(history, nodes, true);
assert.deepEqual(computeVisitStates(nodes, history)['g:2'],
    {progress: 'seen', newContent: true});
acknowledgeVisitNodes(history, nodes, ['g:1'], true);
assert.equal(computeVisitStates(nodes, history)['g:1'].newContent, true);
acknowledgeVisitNodes(history, nodes, ['g:2'], true);
assert.equal(computeVisitStates(nodes, history)['g:1'].newContent, false);

// Adding a navigable child makes an otherwise complete parent Partial + New.
nodes['g:1'].children.push('g:3');
nodes['g:1'].watermark['e:g:3'] = 3;
nodes['g:3'] = node([], {'m:3:0': 1});
observeVisitGraph(history, nodes, true);
assert.deepEqual(computeVisitStates(nodes, history)['g:1'],
    {progress: 'partial', newContent: true});
assert.deepEqual(computeVisitStates(nodes, history)['g:3'],
    {progress: 'unseen', newContent: true});
acknowledgeVisitNodes(history, nodes, ['g:1'], true);
assert.equal(computeVisitStates(nodes, history)['g:3'].newContent, true);
acknowledgeVisitNodes(history, nodes, ['g:3'], true);
assert.deepEqual(computeVisitStates(nodes, history)['g:1'],
    {progress: 'seen', newContent: false});

// A new root is also discoverable before its first individual card observation.
nodes['g:4'] = node();
nodes['r:0'].children.push('g:4');
nodes['r:0'].watermark['e:g:4'] = 4;
observeVisitGraph(history, nodes, true);
assert.equal(computeVisitStates(nodes, history)['g:4'].newContent, true);
acknowledgeVisitNodes(history, nodes, Object.keys(nodes), true);
assert.equal(Object.values(computeVisitStates(nodes, history)).some(value => value.newContent), false);
assert.equal(history.g['r:0'].o, false, 'The synthetic root is never a real visit.');

// Undo a complete branch, including shared Smart placements, without changing
// content acknowledgements, unrelated roots or a Smart item's physical sources.
const beforeUndo = parseVisitHistory(JSON.stringify(history));
const baseline = JSON.stringify(history.g['g:2'].b);
nodes['g:2'].watermark['m:2:0']++;
markVisitBranchUnseen(history, nodes, 'g:1');
assert.deepEqual(computeVisitStates(nodes, history)['g:1'],
    {progress: 'unseen', newContent: true});
assert.equal(history.g['s:1'].o, false);
assert.equal(history.g['g:4'].o, true, 'Unrelated gallery visits are preserved.');
assert.equal(JSON.stringify(history.g['g:2'].b), baseline,
    'Undoing visits must neither acknowledge nor invent New content.');
assert.equal(mergeVisitHistory(history, beforeUndo).g['g:2'].o, false);
assert.equal(mergeVisitHistory(beforeUndo, history).g['g:2'].o, false,
    'A stale writer cannot resurrect a visit after a branch reset.');
acknowledgeVisitNodes(history, nodes, ['g:2'], true);
assert.equal(history.g['g:2'].o, true, 'A later genuine visit can reopen a reset item.');
assert.equal(computeVisitStates(nodes, history)['g:1'].progress, 'partial');
markVisitBranchUnseen(history, nodes, 's:1');
assert.equal(history.g['g:2'].o, true, 'Resetting Smart never resets its physical sources.');
const unchanged = JSON.stringify(history);
markVisitBranchUnseen(history, nodes, 'r:0');
markVisitBranchUnseen(history, nodes, 'g:999');
assert.equal(JSON.stringify(history), unchanged, 'Unknown/synthetic roots cannot reset visits.');

// An already-authorized Smart member moving sources is not a membership addition.
const virtual = {'s:7': node([], {'m:1:0': 10, 'm:1:1': 10, 'm:2:0': 0, 'm:2:1': 0})};
const virtualHistory = parseVisitHistory(null);
observeVisitGraph(virtualHistory, virtual, true);
virtual['s:7'].watermark = {'m:1:0': 0, 'm:1:1': 0, 'm:2:0': 10, 'm:2:1': 10};
assert.equal(computeVisitStates(virtual, virtualHistory)['s:7'].newContent, false);
virtual['s:7'].watermark['m:2:1'] = 11;
assert.equal(computeVisitStates(virtual, virtualHistory)['s:7'].newContent, true);

// Metadata reclassification is quiet for full access; newly granted old NSFW
// membership still alerts even when newer safe content has a higher watermark.
const access = {'g:8': node([], {'m:8:0': 10, 'm:8:1': 10})};
const accessHistory = parseVisitHistory(null);
observeVisitGraph(accessHistory, access, true);
access['g:8'].watermark = {'m:8:1': 10, 'm:8:2': 11};
assert.equal(computeVisitStates(access, accessHistory)['g:8'].newContent, false);
accessHistory.g['g:8'].b = {'m:8:0': 10};
access['g:8'].watermark = {'m:8:0': 10, 'm:8:1': 10, 'm:8:2': 1};
assert.equal(computeVisitStates(access, accessHistory)['g:8'].newContent, true);
assert.equal(computeVisitStates(access, accessHistory, false)['g:8'].newContent, false);

const corrupt = parseVisitHistory('{broken');
assert.deepEqual(corrupt.g, {});
assert.deepEqual(parseVisitHistory('{"v":1,"epoch":"initial","g":{"__proto__":{"o":true,"b":{}}}}').g, {});
const independent = parseVisitHistory(null);
independent.g['g:99'] = {o: true, b: {'m:99:0': 1}};
const merged = mergeVisitHistory(history, independent);
assert.equal(merged.g['g:99'].o, true);
assert.equal(merged.g['g:2'].o, true);
const reset = {v: 1, epoch: 'r_0000000000000002_reset', g: {}};
assert.deepEqual(mergeVisitHistory(reset, history).g, {});
assert.equal(mergeVisitHistory(history, reset).epoch, reset.epoch,
    'An older fallback writer must not overwrite a newer reset.');

/**
 * Exercise the server's full hierarchy admission depth without recursion.
 * Type: number. Units: nodes. Scope: this graph regression.
 * Consumers: the deep bottom-up traversal vector.
 * Rationale: 2048 equals the server's bounded physical/virtual projection size.
 */
const DEEP_NODES = 2048;
const deep = {};
const deepHistory = parseVisitHistory(null);
for (let index = 1; index <= DEEP_NODES; index++) {
    deep[`g:${index}`] = node(index < DEEP_NODES ? [`g:${index + 1}`] : []);
}
observeVisitGraph(deepHistory, deep, true);
acknowledgeVisitNodes(deepHistory, deep, Object.keys(deep), true);
assert.equal(computeVisitStates(deep, deepHistory)['g:1'].progress, 'seen');
delete deep[`g:${DEEP_NODES}`];
assert.equal(computeVisitStates(deep, deepHistory)['g:1'].progress, 'seen');
console.log('PASS gallery visit hierarchy, additions, access lanes, root discovery, bulk acknowledgement and reset merge');
