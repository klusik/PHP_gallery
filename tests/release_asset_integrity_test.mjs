/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/release_asset_integrity_test.mjs
 * Module Type: Regression Test
 * Purpose: Preserve exact-byte manual publication checks after retiring the automatic publisher.
 * Responsibilities:
 *   - Validate all four manuals and complete production/evidence asset inventories
 *   - Refuse corruption, missing assets, duplicate checksum entries and wrong Q/base
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {mkdtempSync,writeFileSync,readFileSync,rmSync,symlinkSync} from 'node:fs';
import {join} from 'node:path';
import {tmpdir} from 'node:os';
import {createHash} from 'node:crypto';
import {verifyPreparedAssets} from '../.github/scripts/release-asset-integrity.mjs';
const directory=mkdtempSync(join(tmpdir(),'gallery-manual-assets-'));
const candidate='a'.repeat(40),sourceBase='b'.repeat(40);
const files=['production.zip','core-manifest.json','production-files.json','release-metadata.json',
    'release-notes.md','qualification-evidence.zip','PHP_Gallery_Manual.pdf','PHP_Gallery_Manual_CZ.pdf',
    'PHP_Gallery_Manual_DE.pdf','PHP_Gallery_Manual_SV.pdf'].sort();
const hashes={};
try {
    for (const name of files) {
        writeFileSync(join(directory,name),'fixture '+name);
        hashes[name]=createHash('sha256').update(readFileSync(join(directory,name))).digest('hex');
    }
    const sums=Object.entries(hashes).map(([name,hash])=>hash+'  '+name).join('\n')+'\n';
    writeFileSync(join(directory,'SHA256SUMS'),sums);
    hashes.SHA256SUMS=createHash('sha256').update(sums).digest('hex');
    const proof={candidate_sha:candidate,source_base:sourceBase,result:'PASS',hashes};
    writeFileSync(join(directory,'final-integrity.json'),JSON.stringify(proof));
    assert.equal(verifyPreparedAssets(directory,candidate,sourceBase).candidate_sha,candidate);
    assert.throws(()=>verifyPreparedAssets(directory,'c'.repeat(40),sourceBase),/exact Q/);
    const first=files[0];writeFileSync(join(directory,first),'corrupted');
    assert.throws(()=>verifyPreparedAssets(directory,candidate,sourceBase),/digest differs/);
    writeFileSync(join(directory,first),'fixture '+first);
    writeFileSync(join(directory,'SHA256SUMS'),sums+sums.split('\n')[0]+'\n');
    proof.hashes.SHA256SUMS=createHash('sha256').update(readFileSync(join(directory,'SHA256SUMS'))).digest('hex');
    writeFileSync(join(directory,'final-integrity.json'),JSON.stringify(proof));
    assert.throws(()=>verifyPreparedAssets(directory,candidate,sourceBase),/duplicated/);
    rmSync(join(directory,'PHP_Gallery_Manual_SV.pdf'));
    assert.throws(()=>verifyPreparedAssets(directory,candidate,sourceBase),/incomplete/);
    symlinkSync(join(directory,first),join(directory,'PHP_Gallery_Manual_SV.pdf'));
    assert.throws(()=>verifyPreparedAssets(directory,candidate,sourceBase),/unsafe/);
} finally {rmSync(directory,{recursive:true,force:true});}
process.stdout.write('PASS complete manual assets, SHA-256 corruption, duplicate checksum, symlink and exact-Q binding\n');
