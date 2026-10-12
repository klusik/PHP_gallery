/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/develop_integration_test.mjs
 * Module Type: Hosted Integration Publisher Contract Test
 * Purpose: Exercise protected develop integration validation against inert GitHub API and artifact fixtures.
 * Responsibilities:
 *   - Prove source S is one direct commit over P and candidate A is S or one generated-only child
 *   - Bind BASIC to the latest exact source qualification and FULL to all actual native owners on A
 *   - Reject stale, adverse, incomplete, duplicate and wrong-context evidence before any ref write
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {integrateDevelop,publishPreparedCandidate,selectDevelopIntegration,validBranchName,validateDevelopIntegration} from '../.github/scripts/develop-integration.mjs';

const repository='klusik/PHP_gallery';
const sourceSha='a'.repeat(40),candidateSha='b'.repeat(40),developSha='d'.repeat(40);
const workflowSha='f'.repeat(40),candidateTree='c'.repeat(40),sourceTree='1'.repeat(40),developTree='0'.repeat(40);
const branch='feature/185_#source,selected';
const context={repository,workflowPath:'.github/workflows/develop-integration.yml',
    workflowRef:repository+'/.github/workflows/develop-integration.yml@refs/heads/main',eventName:'workflow_dispatch',
    ref:'refs/heads/main',runId:901,runAttempt:2,headSha:workflowSha};
const request={branch,sourceSha,candidateSha,expectedDevelopSha:developSha,sourceBase:developSha,
    basicRunId:801,basicRunAttempt:3};
const ownerNames=['Read-only generated-state and source preflight',
    'Positive production package (ubuntu-24.04)','Positive production package (windows-2025)',
    'Positive production package (macos-latest)','PHP 8.3 workflows (mysql:8.4, Unicode)',
    'PHP 8.3 workflows (mariadb:10.11, Unicode)','PHP 8.5 workflows (mariadb:11.4, Unicode)',
    'PHP 8.1 source (unicode)','PHP 8.5 source (ascii)','Required Chromium fixtures','Complete required CI matrix'];
const basicAuthorityPaths=['.github/workflows/candidate-preparation.yml','scripts/audit.php','scripts/cli_guard.php',
    'scripts/audit_lib.php','scripts/audit_process.php','scripts/audit_performance.php','scripts/audit_source_evidence.php',
    'scripts/audit_route_performance.php','scripts/audit_winapp.php','scripts/runtime_plan_metrics.php','scripts/audit_registry.php','scripts/audit_php_registry.php',
    'scripts/audit_performance_registry.php','scripts/audit_route_probe_registry.php','scripts/source_contract_debt_baseline.json',
    'scripts/check_source_documentation.php','scripts/source_contracts/debt_ratchet.php','scripts/source_contracts/inventory.php',
    'scripts/source_contracts/php.php','scripts/source_contracts/javascript.php','scripts/source_contracts/python.php',
    'scripts/source_contracts/scripts.php','scripts/source_contracts/changes.php','scripts/source_contracts/python_scan.py'];

/** Produce complete recursive-tree rows including directory objects and BASIC executable inputs.
 * @param {string} treeKind Exact fixture identity for directory and generated leaf hashes.
 * @returns {Array<{path:string,mode:string,type:string,sha:string}>} Valid recursive Git tree entries.
 */
function treeRows(treeKind) {
    const variant=treeKind==='candidate'? '9':'8';
    const rows=[
        {path:'.github',mode:'040000',type:'tree',sha:variant.repeat(40)},
        {path:'.github/workflows',mode:'040000',type:'tree',sha:variant.repeat(40)},
        {path:'app',mode:'040000',type:'tree',sha:variant.repeat(40)},
        {path:'app/runtime',mode:'040000',type:'tree',sha:variant.repeat(40)},
        {path:'scripts',mode:'040000',type:'tree',sha:variant.repeat(40)},
        {path:'scripts/source_contracts',mode:'040000',type:'tree',sha:variant.repeat(40)},
    ];
    for (const [index,path] of basicAuthorityPaths.entries())
        rows.push({path,mode:'100644',type:'blob',sha:(index+10).toString(16).repeat(40).slice(0,40)});
    const generated=['app/runtime/modules.php','app/production-files.json','app/core-manifest.json'];
    for (const [index,path] of generated.entries()) {
        const digit=treeKind==='candidate' ? String(index+4) : String(index+1);
        rows.push({path,mode:'100644',type:'blob',sha:digit.repeat(40)});
    }
    return rows;
}

/** Calculate the ZIP checksum used by the inert stored-entry archive.
 * @param {Buffer} bytes Complete uncompressed file bytes.
 * @returns {number} Unsigned ZIP CRC-32.
 */
function crc32(bytes) {
    let crc=0xffffffff;
    for (const byte of bytes) {crc^=byte;for (let bit=0;bit<8;bit++) crc=(crc>>>1)^((crc&1)?0xedb88320:0);}
    return (crc^0xffffffff)>>>0;
}

/** Assemble a minimal complete stored ZIP for the three generated data files.
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

/** Build an inert successful evidence surface with mutable remote refs and captured writes.
 * @param {string} sourceHead Initial remote feature head; defaults to the prepared candidate A.
 * @returns {{call:function(string,string=,(Record<string,unknown>|null)=):Record<string,unknown>,state:{source:string,develop:string,updates:Array<Record<string,unknown>>}}} API fixture and mutable refs.
 */
function fixture(sourceHead=candidateSha) {
    const state={source:sourceHead,develop:developSha,updates:[],checks:[]};
    const basic={id:1001,name:'Basic source qualification',app:{id:15368},head_sha:sourceSha,
        check_suite:{id:7001},external_id:'basic:801:3:'+sourceSha,status:'completed',conclusion:'success'};
    const jobs=ownerNames.map((name,index)=>({id:2000+index,name:'Develop integration / '+name,run_id:901,
        run_attempt:2,workflow_name:'Integrate into develop',status:'completed',conclusion:'success'}));
    for (const [index,name] of ['Select squashed source','Prepare integration artifacts','Publish prepared integration candidate'].entries())
        jobs.push({id:2011+index,name,run_id:901,run_attempt:2,workflow_name:'Integrate into develop',status:'completed',conclusion:'success'});
    jobs.push({id:2014,name:'Publish develop integration',run_id:901,
        run_attempt:2,workflow_name:'Integrate into develop',status:'in_progress',conclusion:null});
    const call=(path,method='GET',payload=null)=>{
        if (path==='repos/'+repository+'/git/ref/heads/'+encodeURIComponent(branch).replace(/%2F/gi,'/'))
            return {ref:'refs/heads/'+branch,object:{type:'commit',sha:state.source}};
        if (path==='repos/'+repository+'/git/ref/heads/develop')
            return {ref:'refs/heads/develop',object:{type:'commit',sha:state.develop}};
        if (path==='repos/'+repository+'/actions/runs/801') return {id:801,path:'.github/workflows/candidate-preparation.yml',
            head_branch:branch,head_sha:sourceSha,event:'push',status:'completed',conclusion:'success',run_attempt:3,check_suite_id:7002};
        if (path==='repos/'+repository+'/actions/workflows/candidate-preparation.yml/runs?branch='
            +encodeURIComponent(branch)+'&head_sha='+sourceSha+'&per_page=100&page=1') return {total_count:1,workflow_runs:[
                {id:801,head_branch:branch,head_sha:sourceSha,event:'push',status:'completed',conclusion:'success',run_attempt:3,
                    run_number:1,created_at:'2026-10-12T00:00:00Z'}]};
        if (path==='repos/'+repository+'/check-suites/7001') return {id:7001,head_branch:branch,head_sha:sourceSha,
            status:'completed',conclusion:'success',app:{id:15368}};
        if (path==='repos/'+repository+'/check-suites/7002') return {id:7002,head_branch:branch,head_sha:sourceSha,
            status:'completed',conclusion:'success',app:{id:15368}};
        if (path==='repos/'+repository+'/actions/runs/801/attempts/3/jobs?per_page=100&page=1') return {total_count:1,jobs:[
            {id:1901,name:'Basic source checks',run_id:801,run_attempt:3,workflow_name:'Basic source checks',status:'completed',conclusion:'success'}]};
        if (path==='repos/'+repository+'/actions/runs/901') return {id:901,path:'.github/workflows/develop-integration.yml',
            head_branch:'main',head_sha:workflowSha,event:'workflow_dispatch',status:'in_progress',run_attempt:2};
        if (path==='repos/'+repository+'/actions/runs/901/attempts/2/jobs?per_page=100&page=1') return {total_count:jobs.length,jobs};
        if (path==='repos/'+repository+'/git/trees/'+sourceTree+'?recursive=1') return {sha:sourceTree,truncated:false,tree:treeRows('source')};
        if (path==='repos/'+repository+'/git/trees/'+candidateTree+'?recursive=1') return {sha:candidateTree,truncated:false,tree:treeRows('candidate')};
        if (path==='repos/'+repository+'/git/trees/'+developTree+'?recursive=1') return {sha:developTree,truncated:false,tree:treeRows('develop')};
        if (path==='repos/'+repository+'/commits/'+developSha) return {sha:developSha,commit:{tree:{sha:developTree}},parents:[]};
        if (path==='repos/'+repository+'/commits/'+sourceSha) return {sha:sourceSha,commit:{tree:{sha:sourceTree}},parents:[{sha:developSha}]};
        if (path==='repos/'+repository+'/commits/'+candidateSha) return {sha:candidateSha,commit:{tree:{sha:candidateTree}},parents:[{sha:sourceSha}]};
        if (path==='repos/'+repository+'/compare/'+sourceSha+'...'+candidateSha) return {base_commit:{sha:sourceSha},head_commit:{sha:candidateSha},
            ahead_by:1,behind_by:0,status:'ahead',files:[{filename:'app/runtime/modules.php'},{filename:'app/production-files.json'},{filename:'app/core-manifest.json'}]};
        if (path==='repos/'+repository+'/commits/'+sourceSha+'/check-runs?filter=all&per_page=100&page=1') return {total_count:1,check_runs:[basic]};
        if (path==='repos/'+repository+'/commits/'+candidateSha+'/check-runs?filter=all&per_page=100&page=1') return {total_count:state.checks.length,check_runs:state.checks};
        if (path==='repos/'+repository+'/check-runs' && method==='POST') {
            const check={id:state.checks.length+3000,name:payload.name,head_sha:payload.head_sha,external_id:payload.external_id,
                app:{id:15368},status:'completed',conclusion:'success'};state.checks.push(check);return check;
        }
        if (path==='repos/'+repository+'/git/refs/heads/develop' && method==='PATCH') {
            state.updates.push({path,method,payload});state.develop=payload.sha;return {ref:'refs/heads/develop',object:{type:'commit',sha:payload.sha}};
        }
        throw new Error('Unexpected fixture request: '+method+' '+path);
    };
    return {call,state};
}

assert.equal(validBranchName(branch),true,'comma and hash are valid Git branch characters');
for (const invalid of ['feature/a b','feature/a#x\\y','feature/a..b','feature/.hidden','feature/end.lock'])
    assert.equal(validBranchName(invalid),false,'invalid ref '+invalid+' must fail');

{
    const f=fixture(sourceSha);
    // REST-created custom checks can share a prior suite while this native BASIC attempt owns a new suite.
    assert.deepEqual(selectDevelopIntegration(branch,context,repository,f.call),{branch,sourceSha,
        expectedDevelopSha:developSha,sourceBase:developSha,basicRunId:'801',basicRunAttempt:'3'});
}

for (const [label,patch] of [
    ['foreign native suite',{head_branch:'feature/other'}],
    ['red native suite',{conclusion:'failure'}],
    ['wrong native suite App',{app:{id:1}}],
    ['native suite on another commit',{head_sha:'e'.repeat(40)}],
]) {
    const f=fixture(sourceSha),original=f.call;
    f.call=(path,...args)=>path.endsWith('/check-suites/7002')
        ? {...original(path,...args),...patch} : original(path,...args);
    assert.throws(()=>selectDevelopIntegration(branch,context,repository,f.call),/BLOCKED/,label+' rejected');
}

{
    const f=fixture();
    const checked=validateDevelopIntegration(request,context,repository,f.call);
    assert.deepEqual(checked.plan,{branch:'develop',oldSha:developSha,newSha:candidateSha});
    assert.equal(checked.record.native_owners.length,11);
    assert.equal(checked.record.source_sha,sourceSha);
    assert.equal(checked.record.candidate_sha,candidateSha);
}

{
    // Parent directory object IDs change with generated leaves and are not extra source changes.
    const f=fixture(),original=f.call;
    f.call=(path,...args)=>path.endsWith('/git/trees/'+candidateTree+'?recursive=1')
        ? {...original(path,...args),tree:original(path,...args).tree.map(entry=>entry.type==='tree'
            ? {...entry,sha:'7'.repeat(40)} : entry)} : original(path,...args);
    assert.doesNotThrow(()=>validateDevelopIntegration(request,context,repository,f.call));
}

for (const [label,change] of [
    ['wrong repository',c=>{c.repository='attacker/PHP_gallery';}],
        ['candidate-sourced workflow',c=>{c.workflowRef=repository+'/.github/workflows/develop-integration.yml@refs/heads/feature/x';}],
    ['non-dispatch event',c=>{c.eventName='push';}],
    ['wrong workflow file',c=>{c.workflowPath='.github/workflows/candidate-preparation.yml';}],
]) {
    const f=fixture(),bad={...context};change(bad);
    assert.throws(()=>validateDevelopIntegration(request,bad,repository,f.call),/BLOCKED/,label+' rejected');
}

for (const [label,mutate] of [
    ['stale source head',f=>{f.state.source='e'.repeat(40);}],
    ['stale develop lease',f=>{f.state.develop='e'.repeat(40);}],
]) {
    const f=fixture();mutate(f);
    assert.throws(()=>validateDevelopIntegration(request,context,repository,f.call),/BLOCKED/,label+' rejected');
    if (label==='stale develop lease') {
        assert.throws(()=>integrateDevelop(request,context,repository,f.call),/BLOCKED/);
        assert.equal(f.state.updates.length,0,'a stale lease cannot write develop');
        assert.equal(f.state.checks.length,0,'a stale lease cannot publish green custom checks');
    }
}

for (const [label,mutate] of [
    ['source is not one direct commit over develop',f=>{f.call=(path,...args)=>path.endsWith('/commits/'+sourceSha)
        ? {sha:sourceSha,commit:{tree:{sha:sourceTree}},parents:[{sha:'e'.repeat(40)}]} : f.original(path,...args);}],
    ['source edits its own BASIC authority workflow',f=>{f.call=(path,...args)=>path.endsWith('/git/trees/'+sourceTree+'?recursive=1')
        ? {...f.original(path,...args),tree:f.original(path,...args).tree.map(entry=>entry.path==='.github/workflows/candidate-preparation.yml'
            ? {...entry,sha:'9'.repeat(40)} : entry)} : f.original(path,...args);}],
    ['source edits transitive BASIC audit code',f=>{f.call=(path,...args)=>path.endsWith('/git/trees/'+sourceTree+'?recursive=1')
        ? {...f.original(path,...args),tree:f.original(path,...args).tree.map(entry=>entry.path==='scripts/audit.php'
            ? {...entry,sha:'9'.repeat(40)} : entry)} : f.original(path,...args);} ],
    ['recursive tree response must identify the requested tree',f=>{f.call=(path,...args)=>path.endsWith('/git/trees/'+sourceTree+'?recursive=1')
        ? {...f.original(path,...args),sha:'e'.repeat(40)} : f.original(path,...args);} ],
    ['candidate includes an unowned path',f=>{f.call=(path,...args)=>path.endsWith('/git/trees/'+candidateTree+'?recursive=1')
        ? {...f.original(path,...args),tree:[...f.original(path,...args).tree,{path:'src/unreviewed.php',mode:'100644',type:'blob',sha:'7'.repeat(40)}]}
        : f.original(path,...args);}],
]) {
    const f=fixture();f.original=f.call;mutate(f);
    assert.throws(()=>validateDevelopIntegration(request,context,repository,f.call),/BLOCKED/,label+' rejected');
}

for (const [label,mutate] of [
    ['BASIC from another source SHA',checks=>{checks[0].head_sha='e'.repeat(40);}],
    ['BASIC external identity from another attempt',checks=>{checks[0].external_id='basic:800:2:'+sourceSha;}],
    ['wrong BASIC workflow path',run=>{run.path='.github/workflows/other.yml';}],
]) {
    const f=fixture(),original=f.call;
    f.call=(path,...args)=>{
        const result=original(path,...args);
        if (label==='wrong BASIC workflow path' && path.endsWith('/actions/runs/801')) mutate(result);
        if (label!=='wrong BASIC workflow path' && path.endsWith('/check-runs?filter=all&per_page=100&page=1')) mutate(result.check_runs);
        return result;
    };
    assert.throws(()=>validateDevelopIntegration(request,context,repository,f.call),/BLOCKED/,label+' rejected');
}

for (const [label,mutate] of [
    ['missing native owner',jobs=>{jobs.pop();}],
    ['red native owner',jobs=>{jobs[0].conclusion='failure';}],
    ['duplicate native owner',jobs=>{jobs.push({...jobs[0],id:9999});}],
    ['wrong native attempt',jobs=>{jobs[0].run_attempt=1;}],
]) {
    const f=fixture(),original=f.call;
    f.call=(path,...args)=>{
        const response=original(path,...args);
        if (path.endsWith('/attempts/2/jobs?per_page=100&page=1')) {mutate(response.jobs);response.total_count=response.jobs.length;}
        return response;
    };
    assert.throws(()=>validateDevelopIntegration(request,context,repository,f.call),/BLOCKED/,label+' rejected');
}

{
    const f=fixture();
    const result=integrateDevelop(request,context,repository,f.call);
    assert.equal(result.sha,candidateSha);
    assert.equal(result.record.check_run_id,3000);
    assert.equal(result.record.matrix_check_run_id,3001);
    assert.deepEqual(f.state.updates[0].payload,{sha:candidateSha,force:false});
}

{
    const generated=new Map([['modules.php',Buffer.from('runtime')],['production-files.json',Buffer.from('{}')],['core-manifest.json',Buffer.from('{}')]]);
    const archive=storedZip(generated),digest='sha256:'+createHash('sha256').update(archive).digest('hex');
    const sourceOnly={...context,runId:901};
    const published=[];let blobCount=0,sourceRef=sourceSha;
    const api=(path,method='GET',payload=null)=>{
        if (path==='repos/'+repository+'/actions/runs/901') return {id:901,path:'.github/workflows/develop-integration.yml',
            head_branch:'main',head_sha:workflowSha,event:'workflow_dispatch',status:'in_progress',run_attempt:2};
        if (path==='repos/'+repository+'/actions/artifacts/44') return {id:44,name:'develop-integration-prepared-state',digest,
            expired:false,workflow_run:{id:901},size_in_bytes:archive.length};
        if (path==='repos/'+repository+'/git/ref/heads/'+encodeURIComponent(branch).replace(/%2F/gi,'/')) return {object:{sha:sourceRef}};
        if (path==='repos/'+repository+'/git/ref/heads/develop') return {object:{sha:developSha}};
        if (path==='repos/'+repository+'/commits/'+sourceSha) return {sha:sourceSha,commit:{tree:{sha:sourceTree}},parents:[{sha:developSha}]};
        if (path==='repos/'+repository+'/git/blobs') return {sha:String(++blobCount).repeat(40)};
        if (path==='repos/'+repository+'/git/trees') {published.push({kind:'tree',payload});return {sha:candidateTree};}
        if (path==='repos/'+repository+'/git/commits') {published.push({kind:'commit',payload});return {sha:candidateSha};}
        if (path==='repos/'+repository+'/git/refs/heads/'+encodeURIComponent(branch).replace(/%2F/gi,'/') && payload) {
            published.push({kind:'ref',payload});sourceRef=payload.sha;return {object:{sha:payload.sha}};
        }
        throw new Error('Unexpected artifact fixture request '+path);
    };
    const result=publishPreparedCandidate({repository,branch,sourceSha,expectedDevelopSha:developSha,
        artifactId:44,artifactDigest:digest,runId:901},sourceOnly,
        (path,method,payload)=>api(path,method,payload),{api,download:()=>archive});
    assert.equal(result.candidateSha,candidateSha);
    assert.equal(published.find(item=>item.kind==='commit').payload.parents[0],sourceSha);
    sourceRef=sourceSha;
    const noOp=publishPreparedCandidate({repository,branch,sourceSha,expectedDevelopSha:developSha,
        artifactId:44,artifactDigest:digest,runId:901},sourceOnly,
        (path,method,payload)=>path==='repos/'+repository+'/git/trees'
            ? {sha:sourceTree} : api(path,method,payload),
        {api:path=>path==='repos/'+repository+'/git/trees' ? {sha:sourceTree} : api(path),download:()=>archive});
    assert.equal(noOp.candidateSha,sourceSha,'identical generated state reuses S instead of creating a no-op child');
    const before=published.length;
    assert.throws(()=>publishPreparedCandidate({repository,branch,sourceSha,expectedDevelopSha:developSha,
        artifactId:44,artifactDigest:'sha256:'+'0'.repeat(64),runId:901},sourceOnly,
        (path,method,payload)=>api(path,method,payload),{api,download:()=>archive}),/BLOCKED/);
    assert.equal(published.length,before,'a provider digest mismatch cannot create candidate Git objects or refs');
}
