/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_consistency_proposals_test.mjs
 * Module Type: Regression Test
 * Purpose: Keep displayed paths aligned with selected parent/child repairs.
 * Responsibilities: Exercise production preview policy without a browser or
 *     filesystem, retaining separators and unrelated sibling prefixes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL(
    '../public/assets/gallery-modules/gallery-consistency.js', import.meta.url,
), 'utf8');
const start = source.indexOf('export function consistencyProposedPath(');
const end = source.indexOf('export async function consistencyRunBrowserTask', start);
assert.ok(start >= 0 && end > start, 'Production preview owner must exist.');
const context = vm.createContext({Map});
vm.runInContext(source.slice(start, end).replace('export function', 'function'), context);
const preview = context.consistencyProposedPath;
const moves = [
    {key: 'parent', path: 'Parent Folder', leaf: 'parent-folder'},
    {key: 'child', path: 'Parent Folder/Child Folder', leaf: 'child-folder'},
    {key: 'other', path: 'Parent Folder Other', leaf: 'parent-folder-other'},
];
const path = 'Parent Folder/Child Folder/Day 01';
const independent = 'Parent Folder/Child Folder/day-01';
assert.equal(preview(path, independent, moves, new Set()), independent);
assert.equal(preview(path, independent, moves, new Set(['parent'])),
    'parent-folder/Child Folder/day-01');
assert.equal(preview(path, independent, moves, new Set(['child'])),
    'Parent Folder/child-folder/day-01');
assert.equal(preview(path, independent, moves, new Set(['parent', 'child'])),
    'parent-folder/child-folder/day-01');
assert.equal(preview(path, independent, moves, new Set(['other'])), independent);
assert.equal(preview(path, 'Parent Folder/Child Folder', moves,
    new Set(['parent', 'child'])), 'parent-folder/child-folder');
assert.equal(preview(path, '', moves, new Set(['parent'])), '');
console.log('Consistency path previews retain selected ancestors and hierarchy: PASS');
