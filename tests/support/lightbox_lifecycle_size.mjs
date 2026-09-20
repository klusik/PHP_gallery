/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/lightbox_lifecycle_size.mjs
 * Module Type: Test Fixture
 * Purpose: Measure lightbox source and compression size reproducibly.
 * Responsibilities:
 *   - Report source accounting without presenting it as browser performance.
 * Author: Rudolf Klusal
 * Reproducible source/compression accounting, not a browser benchmark.
 */
import {execFileSync} from 'node:child_process';
import {createHash} from 'node:crypto';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {brotliCompressSync, constants, gzipSync} from 'node:zlib';

const baseline = process.argv[2];
if (!/^[a-f0-9]{40}$/.test(baseline || '')) {
    throw new Error('Usage: node tests/support/lightbox_lifecycle_size.mjs <full baseline commit>');
}
const root = fileURLToPath(new URL('../../', import.meta.url));
const viewer = 'public/assets/gallery-modules/lightbox.js';
const lifecycle = 'public/assets/gallery-modules/lightbox-preload-lifecycle.js';
function measure(path, source) {
    // Git blobs use LF; normalize working-tree CRLF to compare equivalent source.
    const bytes = Buffer.from(source.toString('utf8').replace(/\r\n/g, '\n'));
    return {path, sha256: createHash('sha256').update(bytes).digest('hex'),
        lines: bytes.toString('utf8').trimEnd().split('\n').length,
        source: bytes.length, gzip: gzipSync(bytes, {level: 9}).length,
        brotli: brotliCompressSync(bytes, {params: {[constants.BROTLI_PARAM_QUALITY]: 11}}).length};
}
function total(rows) {
    return Object.fromEntries(['lines', 'source', 'gzip', 'brotli'].map(key =>
        [key, rows.reduce((sum, row) => sum + row[key], 0)]));
}
const before = [measure(viewer, execFileSync('git', ['show', `${baseline}:${viewer}`], {cwd: root}))];
const after = [viewer, lifecycle].map(path => measure(path, readFileSync(new URL(path, new URL('../../', import.meta.url)))));
const beforeTotal = total(before);
const afterTotal = total(after);
console.log(JSON.stringify({baseline, node: process.version, zlib: process.versions.zlib,
    brotli: process.versions.brotli, method: 'UTF-8 LF; gzip level 9; Brotli quality 11; sum separate assets',
    before, after, beforeTotal, afterTotal,
    delta: Object.fromEntries(Object.keys(beforeTotal).map(key => [key, afterTotal[key] - beforeTotal[key]]))}, null, 2));
