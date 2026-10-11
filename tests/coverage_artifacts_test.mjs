/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/coverage_artifacts_test.mjs
 * Module Type: Regression Test
 * Purpose: Reject corrupt or unauthenticated coverage bytes before consuming hosted evidence.
 * Responsibilities:
 *   - Exercise real ZIP parsing and provider transport with inert archives
 *   - Refuse newest red integration checks without selecting an older green record
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {deflateRawSync} from 'node:zlib';
import {parseVerifiedZip} from '../.github/scripts/artifact-data.mjs';
import {readCoverageArtifact,loadIntegrationQualification} from '../.github/scripts/coverage-artifacts.mjs';

const crc32=bytes=>{
    let crc=0xffffffff;
    for (const byte of bytes) {
        crc^=byte;
        for (let bit=0;bit<8;bit++) crc=(crc>>>1)^((crc&1)?0xedb88320:0);
    }
    return (crc^0xffffffff)>>>0;
};
const makeZip=(files,method=0,descriptor=false)=>{
    const locals=[],central=[];
    let offset=0;
    for (const [name,bytes] of files) {
        const label=Buffer.from(name),encoded=method===8?deflateRawSync(bytes):bytes;
        const crc=crc32(bytes),flags=descriptor?8:0;
        const local=Buffer.alloc(30),entry=Buffer.alloc(46);
        local.writeUInt32LE(0x04034b50);local.writeUInt16LE(20,4);local.writeUInt16LE(flags,6);
        local.writeUInt16LE(method,8);local.writeUInt16LE(label.length,26);
        if (!descriptor) {local.writeUInt32LE(crc,14);local.writeUInt32LE(encoded.length,18);local.writeUInt32LE(bytes.length,22);}
        const tail=descriptor?Buffer.alloc(16):Buffer.alloc(0);
        if (descriptor) {tail.writeUInt32LE(0x08074b50);tail.writeUInt32LE(crc,4);tail.writeUInt32LE(encoded.length,8);tail.writeUInt32LE(bytes.length,12);}
        entry.writeUInt32LE(0x02014b50);entry.writeUInt16LE(20,6);entry.writeUInt16LE(flags,8);
        entry.writeUInt16LE(method,10);entry.writeUInt32LE(crc,16);entry.writeUInt32LE(encoded.length,20);
        entry.writeUInt32LE(bytes.length,24);entry.writeUInt16LE(label.length,28);entry.writeUInt32LE(offset,42);
        const block=Buffer.concat([local,label,encoded,tail]);
        locals.push(block);central.push(Buffer.concat([entry,label]));offset+=block.length;
    }
    const directory=Buffer.concat(central),end=Buffer.alloc(22);
    end.writeUInt32LE(0x06054b50);end.writeUInt16LE(files.length,8);end.writeUInt16LE(files.length,10);
    end.writeUInt32LE(directory.length,12);end.writeUInt32LE(offset,16);
    return Buffer.concat([...locals,directory,end]);
};
const digest=bytes=>'sha256:'+createHash('sha256').update(bytes).digest('hex');
const files=[['record.json',Buffer.from('{"candidate":"exact"}\n')],['report.json',Buffer.from('{}\n')]];
for (const method of [0,8]) for (const descriptor of [false,true]) {
    const bytes=makeZip(files,method,descriptor);
    const actual=parseVerifiedZip(bytes,digest(bytes),{allowedNames:files.map(row=>row[0]),maxBytes:4096});
    assert.deepEqual([...actual],files);
}
const archive=makeZip(files),options={allowedNames:files.map(row=>row[0]),maxBytes:4096};
assert.throws(()=>parseVerifiedZip(archive,'sha256:'+'0'.repeat(64),options),/digest/);
assert.throws(()=>parseVerifiedZip(archive,digest(archive),{...options,maxBytes:22}),/size/);
for (const corrupt of [
    bytes=>{bytes[30+'record.json'.length]^=1;},
    bytes=>{bytes.writeUInt16LE(1,6);},
    bytes=>{bytes.writeUInt32LE(0xffffffff,18);},
    bytes=>{bytes.writeUInt32LE(1,bytes.length-6);},
]) {
    const bytes=Buffer.from(archive);corrupt(bytes);
    assert.throws(()=>parseVerifiedZip(bytes,digest(bytes),options),/BLOCKED/);
}
for (const malformed of [makeZip([files[0],files[0]]),makeZip([['../record.json',files[0][1]],files[1]])]) {
    assert.throws(()=>parseVerifiedZip(malformed,digest(malformed),options),/BLOCKED/);
}
const provider={id:7,name:'fixture',digest:digest(archive),expired:false,size_in_bytes:archive.length,workflow_run:{id:9}};
const adapters={api:()=>provider,download:()=>archive};
assert.equal(readCoverageArtifact('owner/gallery',7,'9',provider.digest,'fixture',options.allowedNames,adapters).provider.artifact_id,7);
for (const patch of [{expired:true},{id:8},{digest:'sha256:'+'0'.repeat(64)},{workflow_run:{id:10}},{size_in_bytes:archive.length+1}]) {
    assert.throws(()=>readCoverageArtifact('owner/gallery',7,'9',provider.digest,'fixture',options.allowedNames,
        {...adapters,api:()=>({...provider,...patch})}),/BLOCKED/);
}
const sha='a'.repeat(40),checks=[
    {id:11,name:'Develop integration qualification',head_sha:sha,app:{id:15368},external_id:`integration:10:1:${sha}`,status:'completed',conclusion:'success'},
    {id:12,name:'Develop integration qualification',head_sha:sha,app:{id:15368},external_id:`integration:11:1:${sha}`,status:'in_progress',conclusion:null},
];
assert.throws(()=>loadIntegrationQualification('owner/gallery',sha,{api:path=>{
    assert.match(path,/check-runs/);return {total_count:checks.length,check_runs:checks};
}}),/newest retained/);
process.stdout.write('PASS provider digest, complete ZIP bytes, corruption refusals and newest integration selection\n');
