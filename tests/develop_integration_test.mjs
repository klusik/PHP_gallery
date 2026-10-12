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
import {readFileSync,mkdtempSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {spawnSync} from 'node:child_process';
import {buildBootstrapCandidate,requireBrokenAuthority,resolveIntegrationBootstrap,verifyBootstrapChain,validateBootstrapSource} from '../.github/scripts/integration-bootstrap.mjs';
import {integrateDevelop,recoverDevelop,publishPreparedCandidate,selectDevelopIntegration,validBranchName,validateDevelopIntegration} from '../.github/scripts/develop-integration.mjs';
import {verifyIntegrationEvidence} from '../.github/scripts/release-promotion.mjs';

/** Create real isolated Git objects for the source/origin/overlay recovery ancestry contract.
 * @returns {{record:import('../.github/scripts/integration-bootstrap.mjs').BootstrapRecord,origin:{schema_version:number,version:string,release_branch:string,selected_develop_sha:string,initial_main_sha:string,previous_stable_tag:string,previous_stable_sha:string},git:function(string,string[],(string|null)=):string,cleanup:function():void}} Disposable tree builder and exact recovery identities; no network or user refs are used.
 */
function bootstrapFixture() {
    const directory=mkdtempSync(join(tmpdir(),'gallery-integration-bootstrap-'));
    const git=(executable,args,input=null)=>{
        assert.equal(executable,'git');
        assert.ok(!['push','fetch','checkout','update-ref'].includes(args[0]),'fixture never touches remote/protected refs');
        const result=spawnSync(executable,args,{cwd:directory,encoding:'utf8',input,
            env:{...process.env,GIT_AUTHOR_NAME:'Fixture',GIT_AUTHOR_EMAIL:'fixture@example.invalid',
                GIT_COMMITTER_NAME:'Fixture',GIT_COMMITTER_EMAIL:'fixture@example.invalid'}});
        assert.equal(result.status,0,result.stderr);
        return args.includes('-z') || args[0]==='diff'?result.stdout:result.stdout.trim();
    };
    git('git',['init','--quiet']);
    const leaf=content=>git('git',['hash-object','-w','--stdin'],content);
    const tree=rows=>git('git',['mktree'],rows.join('\n')+'\n');
    const sourceWorkflows=tree([
        '100644 blob '+leaf('# Fixture workflow\n      artifact_digest: sha256:${{ steps.upload.outputs.artifact-digest }}\n')+'\tdevelop-integration.yml',
        '100644 blob '+leaf('# Fixture workflow\n      delta_artifact_sha256: sha256:${{ steps.upload-delta.outputs.artifact-digest }}\n')+'\trelease-qualification.yml']);
    const empty=git('git',['mktree'],'');
    const develop=git('git',['commit-tree',empty,'-m','Actual develop']);
    const sourceTree=tree(['040000 tree '+tree(['040000 tree '+sourceWorkflows+'\tworkflows'])+'\t.github']);
    const source=git('git',['commit-tree',sourceTree,'-p',develop,'-m','Reviewed digest repair']);
    const main='7dcfadf153b1c941fd81edee43272f8accb475a1';
    return {record:{schema_version:1,repository:'klusik/PHP_gallery',version:'0.127.4',main_sha:main,develop_sha:develop,
        source_sha:source,source_tree:sourceTree,source_branch:'feature/recovery',retained_origin_sha:null,reason:'upload-artifact-digest-prefix'},
        origin:{schema_version:1,version:'0.127.4',release_branch:'release/v_0.127.4',selected_develop_sha:develop,
            initial_main_sha:main,previous_stable_tag:'v_0.127.2',previous_stable_sha:main},git,
        cleanup:()=>rmSync(directory,{recursive:true,force:true})};
}

{
    const f=bootstrapFixture();
    try {
        const built=buildBootstrapCandidate(f.record,f.origin,{previous_stable_tag:'v_0.127.2'},f.git);
        verifyBootstrapChain(f.record,built.candidate_sha,f.git);
        const retainedRecord={...f.record,retained_origin_sha:built.origin_sha};
        const reused=buildBootstrapCandidate(retainedRecord,f.origin,{previous_stable_tag:'v_0.127.2'},f.git);
        assert.equal(reused.origin_sha,built.origin_sha,'retained recovery reuses the exact original O object');
        assert.equal(f.git('git',['rev-parse',reused.candidate_sha+'^']),built.origin_sha);
        assert.throws(()=>resolveIntegrationBootstrap(f.record.repository,reused.candidate_sha,f.record.version,
            path=>path.endsWith('/git/ref/heads/main')?{object:{sha:f.record.main_sha}}:{sha:'749a48265f07a10de636ff07bfeacc6c1d9d1cb4'},
            (executable,args,input=null)=>args[0]==='fetch'?'':f.git(executable,args,input)),/known origin/);
        assert.equal(f.git('git',['rev-parse',built.origin_sha+'^']),f.record.develop_sha);
        assert.equal(f.git('git',['rev-parse',built.candidate_sha+'^']),built.origin_sha);
        assert.equal(f.git('git',['for-each-ref']), '','preparation builds objects without creating branch refs');
        assert.equal(f.git('git',['status','--porcelain']), '','preparation leaves checkout and index unchanged');
        const call=path=>path.endsWith('/git/ref/heads/main')?{object:{sha:f.record.main_sha}}
            :{sha:'749a48265f07a10de636ff07bfeacc6c1d9d1cb4'};
        const readOnly=(executable,args,input=null)=>args[0]==='fetch'?'':f.git(executable,args,input);
        assert.deepEqual(resolveIntegrationBootstrap(f.record.repository,built.candidate_sha,f.record.version,call,readOnly),f.record);
        assert.equal(resolveIntegrationBootstrap(f.record.repository,f.record.source_sha,f.record.version,call,readOnly),null,
            'ordinary release source without the marker cannot select recovery FULL');
        for (const changed of [{repository:'other/gallery'},{reason:'skip-full'},{source_tree:'0'.repeat(40)},
            {develop_sha:f.record.source_sha},{source_branch:'develop'}]) {
            assert.throws(()=>validateBootstrapSource({...f.record,...changed},f.git),/BLOCKED/);
        }
        assert.throws(()=>requireBrokenAuthority(f.record.repository,'0'.repeat(40),'749a48265f07a10de636ff07bfeacc6c1d9d1cb4'),/BLOCKED/);
        assert.throws(()=>requireBrokenAuthority(f.record.repository,f.record.main_sha,'0'.repeat(40)),/BLOCKED/);
        assert.throws(()=>resolveIntegrationBootstrap(f.record.repository,built.candidate_sha,'0.127.4',
            path=>path.endsWith('/git/ref/heads/main')?{object:{sha:'0'.repeat(40)}}:call(path),readOnly),/BLOCKED/);
        assert.throws(()=>buildBootstrapCandidate(f.record,{...f.origin,selected_develop_sha:f.record.source_sha},{},f.git),/BLOCKED/);
        const unrelated=f.git('git',['commit-tree',f.git('git',['rev-parse',built.candidate_sha+'^{tree}']),
            '-p',f.record.develop_sha,'-m','Fabricated origin chain']);
        assert.throws(()=>verifyBootstrapChain(f.record,unrelated,f.git),/BLOCKED/);
        const marker='.github/release-bootstrap-recovery/v_0.127.4.json';
        f.git('git',['read-tree',built.candidate_sha]);
        const alteredBlob=f.git('git',['hash-object','-w','--stdin'],JSON.stringify({...f.record,source_tree:'0'.repeat(40)}));
        f.git('git',['update-index','--cacheinfo','100644',alteredBlob,marker]);
        const altered=f.git('git',['commit-tree',f.git('git',['write-tree']),'-p',built.candidate_sha,'-m','Changed marker']);
        assert.throws(()=>verifyBootstrapChain(f.record,altered,f.git),/immutable/);
        const arbitraryTree=f.git('git',['mktree'],f.git('git',['ls-tree',f.record.source_tree])+'\n100644 blob '+alteredBlob+'\tindex.php\n');
        const arbitrary=f.git('git',['commit-tree',arbitraryTree,'-p',f.record.develop_sha,'-m','Unrelated product change']);
        assert.throws(()=>validateBootstrapSource({...f.record,source_sha:arbitrary,source_tree:arbitraryTree},f.git),/repair scope/);
    } finally {f.cleanup();}
}

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

/** GitHub transport independently narrowed by integration validators.
 * @typedef {import('../.github/scripts/develop-integration.mjs').GithubApi} IntegrationApi
 */
/** Captured normal integration fast-forward request.
 * @typedef {{path:string,method:string,payload:{sha:string,force:boolean}}} FixtureRefWrite
 */
/** Captured exact-candidate Actions check metadata.
 * @typedef {{id:number,name:string,head_sha:string,external_id:string,app:{id:number},status:string,conclusion:string}} FixtureCheck
 */
/** Inert provider surface with mutable heads and independently captured metadata/ref writes.
 * @typedef {{call:IntegrationApi,state:{source:string,develop:string,updates:FixtureRefWrite[],checks:FixtureCheck[]}}} IntegrationFixture
 */
/** Build an inert successful evidence surface with mutable remote refs and captured writes.
 * @param {string} sourceHead Initial remote feature head; defaults to the prepared candidate A.
 * @returns {IntegrationFixture} API fixture, mutable refs and captured check/ref writes.
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
    const archive=storedZip(generated),uploadDigest=createHash('sha256').update(archive).digest('hex');
    const digest='sha256:'+uploadDigest;
    // Exercise the workflow-to-helper boundary with the actual bare upload-action output.
    const workflow=readFileSync(new URL('../.github/workflows/develop-integration.yml',import.meta.url),'utf8');
    const expression=workflow.match(/^\s+artifact_digest: (.+)$/m)?.[1].trim();
    assert.ok(expression,'preparation exposes the archive digest');
    const dispatchedDigest=expression.replace('${{ steps.upload.outputs.artifact-digest }}',uploadDigest);
    assert.equal(dispatchedDigest,digest,'upload hex must match algorithm-qualified provider metadata');
    const releaseWorkflow=readFileSync(new URL('../.github/workflows/release-qualification.yml',import.meta.url),'utf8');
    const releaseExpression=releaseWorkflow.match(/^\s+delta_artifact_sha256: (.+)$/m)?.[1].trim();
    assert.ok(releaseExpression,'release delta exposes its archive digest');
    assert.equal(releaseExpression.replace('${{ steps.upload-delta.outputs.artifact-digest }}',uploadDigest),digest,
        'release delta uses the same authenticated artifact format');
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
        artifactId:44,artifactDigest:dispatchedDigest,runId:901},sourceOnly,
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

/** Supply genuine-shaped owner recovery with old maintenance FULL and fresh main-run native owners.
 * @returns {IntegrationFixture} Existing fixture state and independent exact-attempt recovery provider rows.
 */
function recoveryFixture() {
    const f=fixture(sourceSha),original=f.call;
    f.state.develop=sourceSha;
    const previousChecks=['Candidate qualification','Complete required CI matrix'].map((name,index)=>({
        id:1100+index,name,head_sha:sourceSha,external_id:`candidate:801:3:${sourceSha}`,
        app:{id:15368},status:'completed',conclusion:'success'}));
    const previousJobs=[...ownerNames.map((name,index)=>({id:1200+index,
        name:'Full CI for the published prepared SHA / '+name,run_id:801,run_attempt:3,
        workflow_name:'Basic source checks',status:'completed',conclusion:'success'})),
        ...['Prepare source candidate at event SHA','Reject superseded qualification'].map((name,index)=>({
            id:1300+index,name,run_id:801,run_attempt:3,workflow_name:'Basic source checks',status:'completed',conclusion:'success'}))];
    f.call=(path,method='GET',payload=null)=>{
        if (path.endsWith('/collaborators/klusik/permission')) return {permission:'admin'};
        if (path.endsWith('/actions/runs/901')) return {...original(path),actor:{login:'klusik'},triggering_actor:{login:'klusik'}};
        if (path.endsWith('/actions/runs/801') || path.endsWith('/actions/runs/801/attempts/3')) {
            return {...original('repos/'+repository+'/actions/runs/801'),event:'workflow_dispatch'};
        }
        if (path.endsWith('/actions/runs/801/attempts/3/jobs?per_page=100&page=1')) {
            const native=original(path);return {total_count:native.jobs.length+previousJobs.length,jobs:[...native.jobs,...previousJobs]};
        }
        if (path.endsWith('/commits/'+sourceSha+'/check-runs?filter=all&per_page=100&page=1')) {
            const native=original(path);const rows=[...native.check_runs,...previousChecks,...f.state.checks];
            return {total_count:rows.length,check_runs:rows};
        }
        return original(path,method,payload);
    };
    return f;
}
const recoveryRequest={...request,candidateSha:sourceSha};
{
    const f=recoveryFixture();
    assert.equal(selectDevelopIntegration(branch,context,repository,f.call,true).expectedDevelopSha,developSha);
    const recovered=recoverDevelop(recoveryRequest,context,repository,f.call);
    assert.equal(recovered.sha,sourceSha);
    assert.equal(recovered.record.schema_version,1,'retain the artifact protocol accepted by existing release origins');
    assert.equal(recovered.record.recovery_mode,'existing-develop');
    assert.equal(recovered.record.ref_write,false);
    assert.equal(recovered.record.native_owners.length,11,'fresh actual main-run owners, never old job IDs');
    assert.equal(recovered.record.expected_develop_sha,developSha,'retain true historical source parent');
    assert.equal(f.state.updates.length,0,'recovery must never write any Git ref');
    assert.equal(f.state.develop,sourceSha);
    assert.equal(f.state.checks.length,2);
    // Existing release consumers retain schema 1 and all original native owner names.
    const digest='sha256:'+'7'.repeat(64);
    const identity={candidate_sha:sourceSha,candidate_tree:sourceTree,workflow_sha:workflowSha,
        workflow_path:context.workflowPath,run_id:'901',run_attempt:'2',
        check_run_id:recovered.record.check_run_id,artifact_id:7,artifact_sha256:digest};
    const reader=(path,...args)=>{
        if (path.endsWith('/actions/runs/901/attempts/2')) return {id:901,run_attempt:2,
            path:context.workflowPath,event:'workflow_dispatch',head_branch:'main',head_sha:workflowSha,
            name:'Integrate into develop',status:'completed',conclusion:'success'};
        if (path.endsWith('/commits/'+sourceSha+'/check-runs?per_page=100&filter=all')) {
            return {total_count:f.state.checks.length,check_runs:f.state.checks};
        }
        if (path.endsWith('/commits/'+workflowSha)) return {sha:workflowSha};
        if (path.endsWith('/git/ref/heads/main')) return {ref:'refs/heads/main',object:{type:'commit',sha:workflowSha}};
        return f.call(path,...args);
    };
    const native=f.call(`repos/${repository}/actions/runs/901/attempts/2/jobs?per_page=100&page=1`).jobs
        .map(job=>job.name==='Publish develop integration' ? {...job,status:'completed',conclusion:'success'} : job);
    assert.equal(verifyIntegrationEvidence({lifecycle_schema:3,coverage_schema:1,selected_develop_sha:sourceSha,integration:identity},
        repository,{api:reader,integrationJobs:native,integrationEvidence:{payload:recovered.record,
            provider:{artifact_id:7,artifact_digest:digest,run_id:'901'}}}).candidate_sha,sourceSha);
    assert.throws(()=>selectDevelopIntegration(branch,context,repository,f.call),/BLOCKED/,
        'normal integration remains strict when source already equals develop');
}
for (const mutate of [
    f=>{const base=f.call;f.call=(path,...args)=>path.endsWith('/actions/runs/901')
        ? {...base(path,...args),actor:{login:'other'}} : base(path,...args);},
    f=>{const base=f.call;f.call=(path,...args)=>path.endsWith('/actions/runs/901')
        ? {...base(path,...args),triggering_actor:{login:'other'}} : base(path,...args);},
    f=>{const base=f.call;f.call=(path,...args)=>path.endsWith('/collaborators/klusik/permission')
        ? {permission:'read'} : base(path,...args);},
    f=>{f.state.develop='e'.repeat(40);},
    f=>{const base=f.call;f.call=(path,...args)=>path.endsWith('/actions/runs/801/attempts/3')
        ? {...base(path,...args),run_attempt:4} : base(path,...args);},
    f=>{const base=f.call;f.call=(path,...args)=>path.endsWith('/actions/runs/801/attempts/3/jobs?per_page=100&page=1')
        ? {...base(path,...args),jobs:base(path,...args).jobs.map(job=>job.name.includes('Required Chromium') ? {...job,conclusion:'skipped'} : job)} : base(path,...args);},
    f=>{const base=f.call;f.call=(path,...args)=>path.endsWith('/actions/runs/901/attempts/2/jobs?per_page=100&page=1')
        ? {...base(path,...args),jobs:base(path,...args).jobs.map(job=>job.name.includes('Required Chromium') ? {...job,conclusion:'failure'} : job)} : base(path,...args);},
    f=>{const base=f.call;f.call=(path,...args)=>path.endsWith('/commits/'+sourceSha+'/check-runs?filter=all&per_page=100&page=1')
        ? {...base(path,...args),check_runs:base(path,...args).check_runs.map(check=>check.name==='Candidate qualification' ? {...check,head_sha:candidateSha} : check)} : base(path,...args);},
]) {
    const f=recoveryFixture();mutate(f);
    assert.throws(()=>recoverDevelop(recoveryRequest,context,repository,f.call),/BLOCKED/);
    assert.equal(f.state.updates.length,0);
    assert.equal(f.state.checks.length,0,'adverse recovery evidence cannot publish green metadata');
}
{
    const f=recoveryFixture(),base=f.call;
    f.call=(path,method,payload)=>{
        const result=base(path,method,payload);
        if (method==='POST') f.state.develop='e'.repeat(40);
        return result;
    };
    assert.throws(()=>recoverDevelop(recoveryRequest,context,repository,f.call),/BLOCKED/);
    assert.equal(f.state.updates.length,0,'a mid-publication race must never be repaired with a ref write');
}
