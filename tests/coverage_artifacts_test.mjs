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
import {parseVerifiedZip} from '../.github/scripts/artifact-data.mjs';
import {readCoverageArtifact,loadIntegrationQualification} from '../.github/scripts/coverage-artifacts.mjs';
import {coverageFixtureZip as makeZip} from './support/coverage_artifact_fixture.mjs';

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
