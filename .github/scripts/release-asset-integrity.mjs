/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-asset-integrity.mjs
 * Module Type: Manual Release Asset Integrity
 * Purpose: Verify complete prepared publication bytes before a qualified PR is opened.
 * Responsibilities:
 *   - Require production package, four PDFs, notes and qualification archive
 *   - Refuse symlinks, incomplete inventories and conflicting SHA-256 evidence
 *   - Bind every asset to exact Q and its immutable comparison base
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {readFileSync,readdirSync,lstatSync} from 'node:fs';
import {resolve} from 'node:path';
import {createHash} from 'node:crypto';

/** Verify all prepared manual-publication assets, including checksum-file integrity.
 * @param {string} directory Absolute downloaded/staged asset directory.
 * @param {string} candidate Exact qualified Q SHA.
 * @param {string} sourceBase Immutable predecessor comparison SHA.
 * @returns {{candidate_sha:string,source_base:string,hashes:Record<string,string>}} Verified per-filename SHA-256 map and exact identities.
 */
export function verifyPreparedAssets(directory,candidate,sourceBase) {
    const expected=['production.zip','core-manifest.json','production-files.json','release-metadata.json',
        'release-notes.md','qualification-evidence.zip','SHA256SUMS','final-integrity.json',
        'PHP_Gallery_Manual.pdf','PHP_Gallery_Manual_CZ.pdf','PHP_Gallery_Manual_DE.pdf','PHP_Gallery_Manual_SV.pdf'];
    const names=readdirSync(directory).sort();
    if (JSON.stringify(names)!==JSON.stringify(expected.sort())
        || names.some(name=>!lstatSync(resolve(directory,name)).isFile()
            || lstatSync(resolve(directory,name)).isSymbolicLink())) throw new Error('BLOCKED incomplete or unsafe manual release assets.');
    const proof=JSON.parse(readFileSync(resolve(directory,'final-integrity.json'),'utf8'));
    if (proof.candidate_sha!==candidate || proof.source_base!==sourceBase || proof.result!=='PASS'
        || !/^[a-f0-9]{40}$/.test(candidate) || !/^[a-f0-9]{40}$/.test(sourceBase)
        || !proof.hashes || typeof proof.hashes!=='object' || Array.isArray(proof.hashes)) {
        throw new Error('BLOCKED asset integrity is not bound to exact Q/base.');
    }
    const files=names.filter(name=>name!=='final-integrity.json');
    if (JSON.stringify(Object.keys(proof.hashes).sort())!==JSON.stringify(files)) throw new Error('BLOCKED asset hash inventory differs.');
    for (const name of files) {
        const actual=createHash('sha256').update(readFileSync(resolve(directory,name))).digest('hex');
        if (proof.hashes[name]!==actual) throw new Error('BLOCKED asset digest differs: '+name);
    }
    const sums=readFileSync(resolve(directory,'SHA256SUMS'),'utf8').trim().split('\n');
    const listed=new Map();
    for (const line of sums) {
        const match=/^([a-f0-9]{64})  ([A-Za-z0-9_.-]+)$/.exec(line);
        if (!match || listed.has(match[2]) || proof.hashes[match[2]]!==match[1]) throw new Error('BLOCKED checksum entry differs or is duplicated.');
        listed.set(match[2],match[1]);
    }
    if (JSON.stringify([...listed.keys()].sort())!==JSON.stringify(files.filter(name=>name!=='SHA256SUMS'))) {
        throw new Error('BLOCKED checksum file omits prepared assets.');
    }
    return {candidate_sha:candidate,source_base:sourceBase,hashes:proof.hashes};
}
