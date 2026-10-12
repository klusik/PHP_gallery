/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/release_draft_assets_scenarios.mjs
 * Module Type: Regression Test
 * Purpose: Verify exact-attempt draft filling and original companion byte reuse.
 * Responsibilities:
 *   - Exercise interruption, conflict, concurrency and published-release safety
 *   - Refuse foreign run/Q/version and corrupt archives or installer metadata
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {assetHash,qualificationAssets,loadDraftAssets,fillDraftAssets,verifyInstallerPair} from '../../.github/scripts/release-draft-assets.mjs';
/** Calculate the ZIP checksum used by the inert stored-entry archive.
 * @param {Buffer} bytes Complete uncompressed file bytes.
 * @returns {number} Unsigned ZIP CRC-32.
 */
function crc32(bytes) {
    let crc=0xffffffff;
    for (const byte of bytes) {crc^=byte;for (let bit=0;bit<8;bit++) crc=(crc>>>1)^((crc&1)?0xedb88320:0);}
    return (crc^0xffffffff)>>>0;
}

/** Assemble a minimal complete stored ZIP for original release files.
 * @param {Map<string,Buffer>} files Exact flat archive entry bytes.
 * @returns {Buffer} Complete ZIP with central directory and end record.
 */
function storedZip(files) {
    const locals=[],centrals=[];let offset=0;
    for (const [name,bytes] of files) {
        const nameBytes=Buffer.from(name),crc=crc32(bytes),local=Buffer.alloc(30+nameBytes.length);
        local.writeUInt32LE(0x04034b50,0);local.writeUInt16LE(20,4);local.writeUInt32LE(crc,14);
        local.writeUInt32LE(bytes.length,18);local.writeUInt32LE(bytes.length,22);local.writeUInt16LE(nameBytes.length,26);nameBytes.copy(local,30);
        locals.push(local,bytes);
        const central=Buffer.alloc(46+nameBytes.length);central.writeUInt32LE(0x02014b50,0);central.writeUInt16LE(20,4);
        central.writeUInt16LE(20,6);central.writeUInt32LE(crc,16);central.writeUInt32LE(bytes.length,20);
        central.writeUInt32LE(bytes.length,24);central.writeUInt16LE(nameBytes.length,28);central.writeUInt32LE(offset,42);nameBytes.copy(central,46);
        centrals.push(central);offset+=local.length+bytes.length;
    }
    const directory=Buffer.concat(centrals),end=Buffer.alloc(22);end.writeUInt32LE(0x06054b50,0);
    end.writeUInt16LE(files.size,8);end.writeUInt16LE(files.size,10);end.writeUInt32LE(directory.length,12);
    end.writeUInt32LE(offset,16);return Buffer.concat([...locals,directory,end]);
}

const repository='fixture/gallery',q='a'.repeat(40),base='b'.repeat(40),main='c'.repeat(40);
const record={repository,version:'1.2.3',branch:'release/v_1.2.3',run_id:'99',run_attempt:'2',candidate_sha:q,source_base:base,
    previous_stable_tag:'v_1.2.2',previous_stable_sha:base,previous_release_candidate_sha:base,installer_required:true};
const completion={tag:'v_1.2.3',main_sha:main,candidate_sha:q};
const version='1.0.0',exe='PHPGalleryUploader-'+version+'-Setup.exe';
const installer=Buffer.from('original binary');
const pair=new Map([[exe,installer],['winapp-update.json',Buffer.from(JSON.stringify({assets:[{name:exe,version,size:installer.length,sha256:assetHash(installer)}]}))]]);
const files=new Map(['production.zip','core-manifest.json','production-files.json','release-metadata.json','qualification-evidence.zip',
    'PHP_Gallery_Manual.pdf','PHP_Gallery_Manual_CZ.pdf','PHP_Gallery_Manual_DE.pdf','PHP_Gallery_Manual_SV.pdf'].map(name=>[name,Buffer.from('original '+name+'\n')]));
files.set('release-notes.md',Buffer.from('## Version 1.2.3\nOriginal notes\n'));
const hashes=Object.fromEntries([...files].map(([name,bytes])=>[name,assetHash(bytes)]));
files.set('SHA256SUMS',Buffer.from(Object.entries(hashes).map(([name,digest])=>digest+'  '+name).join('\n')+'\n'));
hashes.SHA256SUMS=assetHash(files.get('SHA256SUMS'));
files.set('final-integrity.json',Buffer.from(JSON.stringify({candidate_sha:q,source_base:base,repository,version:record.version,run_id:record.run_id,run_attempt:record.run_attempt,result:'PASS',hashes})));
const archives=new Map([[1,storedZip(files)],[2,storedZip(pair)]]);
const artifacts=[...archives].map(([id,bytes])=>({id,name:id===1?'manual-release-assets':'manual-winapp-installer',expired:false,
    size_in_bytes:bytes.length,digest:'sha256:'+assetHash(bytes),created_at:'2026-10-12T10:01:00Z',workflow_run:{id:99,head_branch:record.branch}}));
const run={id:99,run_attempt:2,status:'completed',conclusion:'success',repository:{full_name:repository},head_branch:record.branch,
    path:'.github/workflows/release-qualification.yml',event:'push'};
const jobs=['Prepare manual release assets','Prepare Windows companion installer'].map(name=>({name,status:'completed',conclusion:'success',run_id:99,run_attempt:2,
    started_at:'2026-10-12T10:00:00Z',completed_at:'2026-10-12T10:02:00Z'}));
let published=false,uploadCount=0,mode='',corruptDownload=false,changedSource=false;
const uploaded=new Map(),previous=new Map();
let sequence=100;
const add=(name,bytes,target=uploaded)=>{const id=sequence++;target.set(id,{row:{id,name,state:'uploaded',size:bytes.length,digest:'sha256:'+assetHash(bytes)},bytes});};
for (const [name,bytes] of pair) add(name,bytes,previous);
const call=path=>{
    if (path.includes('/actions/runs/99') && path.includes('/jobs?')) return {total_count:jobs.length,jobs};
    if (path.includes('/actions/runs/99/artifacts?')) return {total_count:artifacts.length,artifacts};
    if (path.includes('/actions/runs/99')) return run;
    if (/actions\/artifacts\/\d+$/.test(path)) return artifacts.find(a=>a.id===Number(path.split('/').at(-1)));
    if (path.endsWith('/git/ref/tags/v_1.2.3')) return {object:{type:'commit',sha:main}};
    if (path.endsWith('/git/ref/tags/v_1.2.2')) return {object:{type:'commit',sha:base}};
    if (path.includes('/commits/')) return {sha:path.split('/').at(-1),commit:{tree:{sha:q}}};
    if (path.endsWith('/releases/tags/v_1.2.2')) return {tag_name:'v_1.2.2',draft:false,prerelease:false,assets:[...previous.values()].map(v=>v.row)};
    if (path.includes('/releases/7/assets?')) return [...uploaded.values()].map(v=>v.row);
    if (path.endsWith('/releases/7')) return {id:7,tag_name:completion.tag,draft:!published,prerelease:false};
    throw new Error('Unexpected fixture endpoint '+path);
};
const download=path=>{
    if (path.endsWith('/zip')) {const bytes=archives.get(Number(path.split('/').at(-2)));return corruptDownload?Buffer.from('wrong'):bytes;}
    const id=Number(path.split('/').at(-1));return (uploaded.get(id) ?? previous.get(id)).bytes;
};
const git=(program,args)=>{
    assert.equal(program,'git');
    if (args[0]==='hash-object' || args[0]==='rev-parse') return q;
    if (args[0]==='diff') return changedSource?'winapp/app.py':'';
    const path=args[1].split(':').slice(1).join(':');
    if (path==='winapp/VERSION') return version;
    if (path==='PATCH_NOTES.md') return files.get('release-notes.md').toString().trim();
    const name=path.split('/').at(-1);return files.get(name).toString().trim();
};
const upload=(repo,id,name,bytes)=>{
    assert.equal(repo,repository);assert.equal(id,7);uploadCount++;
    if (mode==='interrupt') {mode='';throw new Error('Interrupted before upload');}
    add(name,bytes);
    if (mode==='lost' || mode==='concurrent') {mode='';throw new Error('Lost response or concurrent HTTP 422');}
};
const adapters={api:call,download,git,upload};
const loaded=loadDraftAssets(record,adapters);
assert.equal(loaded.size,14);
for (const [name,bytes] of files) assert.ok(loaded.get(name).equals(bytes));
verifyInstallerPair(pair,version);
assert.throws(()=>verifyInstallerPair(pair,'2.0.0'),/version/);
assert.throws(()=>verifyInstallerPair(new Map([...pair].map(([name,bytes])=>[name,name===exe?Buffer.from('corrupt'):bytes])),version),/digest/);
assert.equal(loadDraftAssets({...record,installer_required:false},adapters).size,14);
changedSource=true;assert.throws(()=>loadDraftAssets({...record,installer_required:false},adapters),/source\/version/);changedSource=false;
const original=artifacts[0].created_at;artifacts[0].created_at='2026-10-11T10:01:00Z';
assert.throws(()=>loadDraftAssets(record,adapters),/attempt window/);artifacts[0].created_at=original;
run.run_attempt=3;assert.throws(()=>loadDraftAssets(record,adapters),/run\/attempt/);run.run_attempt=2;
run.repository.full_name='foreign/gallery';assert.throws(()=>loadDraftAssets(record,adapters),/run\/attempt/);run.repository.full_name=repository;
assert.throws(()=>loadDraftAssets({...record,candidate_sha:'d'.repeat(40)},adapters),/exact Q/);
assert.throws(()=>loadDraftAssets({...record,version:'9.9.9'},adapters),/version/);
corruptDownload=true;assert.throws(()=>loadDraftAssets(record,adapters),/size/);corruptDownload=false;
const digest=artifacts[0].digest;artifacts[0].digest='sha256:'+'0'.repeat(64);assert.throws(()=>loadDraftAssets(record,adapters),/digest/);artifacts[0].digest=digest;
const receipt=Buffer.from(JSON.stringify({...record,final_main_sha:main,tag:completion.tag}));
loaded.set('release-evidence.json',receipt);
loaded.set('release-evidence.sha256',Buffer.from(assetHash(receipt)+'  release-evidence.json\n'));
assert.throws(()=>fillDraftAssets(record,completion,7,new Map([...loaded].filter(([name])=>name!=='SHA256SUMS')),adapters),/incomplete/);
mode='interrupt';assert.throws(()=>fillDraftAssets(record,completion,7,loaded,adapters),/missing release asset/);
assert.equal(uploaded.size,0);
for (const [name,bytes] of [...loaded].slice(0,4)) add(name,bytes);
mode='interrupt';assert.throws(()=>fillDraftAssets(record,completion,7,loaded,adapters),/missing release asset/);
assert.equal(uploaded.size,4,'Interruption preserves already uploaded original files.');
mode='lost';assert.equal(fillDraftAssets(record,completion,7,loaded,adapters).status,'DRAFT_READY');
assert.equal(uploaded.size,16);
const count=uploadCount;assert.equal(fillDraftAssets(record,completion,7,loaded,adapters).count,16);assert.equal(uploadCount,count);
uploaded.clear();mode='concurrent';assert.equal(fillDraftAssets(record,completion,7,loaded,adapters).status,'DRAFT_READY');
published=true;const before=uploadCount;assert.throws(()=>fillDraftAssets(record,completion,7,loaded,adapters),/published/);assert.equal(uploadCount,before);published=false;
uploaded.clear();add('production.zip',Buffer.from('conflicting original'));assert.throws(()=>fillDraftAssets(record,completion,7,loaded,adapters),/conflicting/);assert.equal(uploadCount,before);
uploaded.clear();add('production.zip',loaded.get('production.zip'));add('production.zip',loaded.get('production.zip'));assert.throws(()=>fillDraftAssets(record,completion,7,loaded,adapters),/duplicate/);
process.stdout.write('PASS exact run/attempt/Q/version assets, complete filling, interruption, conflict, published safety, concurrency and both companion paths\n');
