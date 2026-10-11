/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/release_reconciliation_test.mjs
 * Module Type: Regression Test
 * Purpose: Prove retained historical reconciliation remains reviewed, exact-SHA qualified and fail-closed.
 * Responsibilities:
 *   - Simulate immutable GitHub publication, ancestry, branch protections and hosted jobs
 *   - Reject incomplete/moved refs and unqualified historical results
 *   - Verify read-only status and retirement of superseded reconciliation writers
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {linearFixture} from './support/linear_release_fixture.mjs';
import {verifyPredecessorLineage} from '../.github/scripts/release-origin.mjs';
import * as historical from '../.github/scripts/release-reconciliation.mjs';
import {inspectSync,verifyLinearContent,qualifiedLinearCandidate,validateSyncRequest,
    syncBranchName,isAncestor,hasEffectiveSyncApproval} from '../.github/scripts/release-reconciliation.mjs';

const request={branch:'release/v_1.2.3',mode:'plan',manualReview:''};
assert.equal(validateSyncRequest(request),'1.2.3');
for (const branch of ['main','develop','release/v_01.2.3','release/v_1.2.3;rm']) {
    assert.throws(()=>validateSyncRequest({...request,branch}),/BLOCKED/);
}
assert.throws(()=>validateSyncRequest({...request,mode:'publish'}),/BLOCKED/);
for (const mode of ['propose','verify']) assert.throws(()=>validateSyncRequest({...request,mode,manualReview:'Historical acceptance'}),/BLOCKED/);
for (const name of ['proposeSync','recordReconciliation','verifySyncWriteControls','main']) {
    assert.equal(historical[name],undefined,'Superseded reconciliation writers have no active API.');
}
assert.throws(()=>syncBranchName('1.2.3','main'),/BLOCKED/);
assert.equal(hasEffectiveSyncApproval([{id:72,state:'APPROVED',user:{login:'fixture'},commit_id:'a'.repeat(40),
    submitted_at:'2026-10-09T19:00:00Z'}],{user:{login:'fixture'},merged_at:'2026-10-09T20:00:00Z'},'fixture','a'.repeat(40)),null);
{
    const f=linearFixture(),sha=f.reconciliation.result_sha;
    const checks=['Candidate qualification','Complete required CI matrix'].map((name,index)=>({id:801+index,name,
        app:{id:15368},head_sha:sha,external_id:'candidate:1234:1:'+sha,status:'completed',conclusion:'success'}));
    const call=path=>{
        if (/\/check-runs\/80[12]$/.test(path)) return checks.find(check=>path.endsWith('/'+check.id));
        const response=f.api(path);
        if (path.includes('/check-runs?')) {
            return {total_count:3,check_runs:[...response.check_runs,{...checks[1],external_id:'candidate:500:1:'+sha,conclusion:'failure'}]};
        }
        if (path.includes('/jobs?')) return {total_count:response.total_count+2,jobs:[...response.jobs,...checks]};
        return response;
    };
    assert.equal(qualifiedLinearCandidate(call,f.repository,sha),'https://github.com/'+f.repository+'/actions/runs/1234',
        'Historical source coverage distinguishes exact programmatic checks and unrelated duplicate contexts.');
    assert.throws(()=>qualifiedLinearCandidate(path=>/\/check-runs\/80[12]$/.test(path)?{...call(path),id:1}:call(path),f.repository,sha),/identity/);
}
// Retained qualification checks must not select an older green run or attempt.
{
    const f=linearFixture(),sha=f.reconciliation.result_sha;
    const base=f.api('repos/'+f.repository+'/commits/'+sha+'/check-runs?per_page=100&filter=all');
    const newer=base.check_runs.map((check,index)=>({...check,id:901+index,external_id:'candidate:2000:1:'+sha}));
    for (const [status,conclusion] of [['completed','failure'],['completed','cancelled'],['completed','skipped'],['in_progress',null],['queued',null]]) {
        const call=path=>path.includes('/check-runs?')
            ? {total_count:4,check_runs:[...base.check_runs,...newer.map(check=>({...check,status,conclusion}))]}:f.api(path);
        assert.equal(qualifiedLinearCandidate(call,f.repository,sha),null,'Newer '+status+'/'+conclusion+' cannot fall back to older green.');
    }
    const newerCall=path=>{
        if (path.includes('/check-runs?')) return {total_count:4,check_runs:[...newer,...base.check_runs]};
        if (path.endsWith('/actions/runs/2000')) return {...f.api('repos/'+f.repository+'/actions/runs/1234'),id:2000};
        if (path.includes('/actions/runs/2000/attempts/1/jobs?')) return f.api('repos/'+f.repository+'/actions/runs/1234/attempts/1/jobs?per_page=100');
        return f.api(path);
    };
    assert.equal(qualifiedLinearCandidate(newerCall,f.repository,sha),'https://github.com/'+f.repository+'/actions/runs/2000');
    const noAggregate=path=>path.includes('/check-runs?')
        ? {total_count:3,check_runs:[...base.check_runs,newer[0]]}:newerCall(path);
    assert.equal(qualifiedLinearCandidate(noAggregate,f.repository,sha),null,'Latest missing aggregate cannot use the old aggregate.');
    const attemptChecks=base.check_runs.map((check,index)=>({...check,id:901+index,external_id:'candidate:1234:2:'+sha}));
    const nextAttempt=path=>{
        if (path.includes('/check-runs?')) return {total_count:4,check_runs:[...base.check_runs,...attemptChecks]};
        if (path.endsWith('/actions/runs/1234')) return {...f.api(path),run_attempt:2};
        if (path.includes('/attempts/2/jobs?')) return f.api(path.replace('/attempts/2/','/attempts/1/'));
        return f.api(path);
    };
    assert.equal(qualifiedLinearCandidate(nextAttempt,f.repository,sha),'https://github.com/'+f.repository+'/actions/runs/1234');
    assert.equal(qualifiedLinearCandidate(path=>path.includes('/check-runs?')
        ? {...nextAttempt(path),check_runs:[...base.check_runs,...attemptChecks.map(check=>({...check,conclusion:'failure'}))]}:nextAttempt(path),f.repository,sha),null);
    assert.equal(qualifiedLinearCandidate(path=>path.endsWith('/actions/runs/1234')
        ? {...nextAttempt(path),run_attempt:3}:nextAttempt(path),f.repository,sha),null,'Native highest attempt must equal the retained binding.');
    assert.equal(qualifiedLinearCandidate(path=>path.includes('/check-runs?')
        ? {total_count:3,check_runs:[...base.check_runs,{...newer[0],external_id:''}]}:f.api(path),f.repository,sha),null);
    assert.throws(()=>qualifiedLinearCandidate(path=>path.includes('/check-runs?')
        ? {total_count:3,check_runs:[...base.check_runs,{...base.check_runs[0],id:901}]}:f.api(path),f.repository,sha),/ambiguous/);
    const foreign=path=>path.includes('/check-runs?') ? {total_count:4,check_runs:[...base.check_runs,
        {...newer[0],app:{id:34598}},{...newer[1],head_sha:'0'.repeat(40)}]}:f.api(path);
    assert.equal(qualifiedLinearCandidate(foreign,f.repository,sha),'https://github.com/'+f.repository+'/actions/runs/1234');
    assert.equal(f.state.writes.length,0);
}
// Complete provider collections accept exact boundaries and reject truncation or conflicts.
{
    const f=linearFixture(),sha=f.reconciliation.result_sha;
    const checkPath='repos/'+f.repository+'/commits/'+sha+'/check-runs?per_page=100&filter=all';
    const jobPath='repos/'+f.repository+'/actions/runs/1234/attempts/1/jobs?per_page=100';
    const checks=f.api(checkPath).check_runs,jobs=f.api(jobPath).jobs;
    const allChecks=[...checks,...Array.from({length:498},(_,index)=>({id:5001+index,name:'Unrelated check '+index,status:'completed',conclusion:'success'}))];
    const allJobs=[...jobs,...Array.from({length:90},(_,index)=>({id:6001+index,name:'Unrelated job '+index,status:'completed',conclusion:'success'}))];
    const pages=path=>{
        if (path.includes('/check-runs?') || path.includes('/jobs?')) {
            const key=path.includes('/check-runs?')?'check_runs':'jobs';
            const rows=key==='check_runs'?allChecks:allJobs;
            const page=Number(/&page=(\d+)/.exec(path)?.[1]??1);
            return {total_count:rows.length,[key]:rows.slice((page-1)*100,page*100)};
        }
        return f.api(path);
    };
    assert.equal(qualifiedLinearCandidate(pages,f.repository,sha),'https://github.com/'+f.repository+'/actions/runs/1234');
    assert.equal(qualifiedLinearCandidate(path=>path.includes('/check-runs?')
        ? {total_count:100,check_runs:allChecks.slice(0,100)}:f.api(path),f.repository,sha),'https://github.com/'+f.repository+'/actions/runs/1234');
    for (const corrupt of [
        response=>({...response,total_count:501}),
        response=>({...response,total_count:-1}),
        response=>({...response,total_count:'2'}),
        response=>({...response,check_runs:response.check_runs.slice(0,-1)}),
        response=>({...response,check_runs:[response.check_runs[0],response.check_runs[0]]}),
        response=>({...response,check_runs:response.check_runs.map(check=>({...check,id:0}))}),
    ]) {
        assert.throws(()=>qualifiedLinearCandidate(path=>path.includes('/check-runs?')?corrupt(f.api(path)):f.api(path),f.repository,sha),/BLOCKED/);
    }
    assert.throws(()=>qualifiedLinearCandidate(path=>path.includes('/check-runs?')&&path.endsWith('&page=2')
        ? {...pages(path),total_count:499}:pages(path),f.repository,sha),/BLOCKED/);
    assert.throws(()=>qualifiedLinearCandidate(path=>path.includes('/check-runs?')&&path.endsWith('&page=2')
        ? {...pages(path),check_runs:[...pages(path).check_runs.slice(0,-1),allChecks[0]]}:pages(path),f.repository,sha),/duplicate/);
    assert.throws(()=>qualifiedLinearCandidate(path=>path.includes('/jobs?')
        ? {...f.api(path),jobs:f.api(path).jobs.slice(0,-1)}:f.api(path),f.repository,sha),/BLOCKED/);
    const duplicateOwner=path=>{
        if (!path.includes('/jobs?')) return f.api(path);
        const response=f.api(path);
        return {total_count:response.total_count+1,jobs:[...response.jobs,{...response.jobs[0],id:7001,conclusion:'failure'}]};
    };
    assert.equal(qualifiedLinearCandidate(duplicateOwner,f.repository,sha),null,'Duplicate red owner is not hidden by its green row.');
    let checkReads=0;
    assert.throws(()=>qualifiedLinearCandidate(path=>{
        const response=f.api(path);
        if (path.includes('/check-runs?') && ++checkReads===2) return {...response,check_runs:response.check_runs.map(check=>({...check,conclusion:'failure'}))};
        return response;
    },f.repository,sha),/raced/);
    let runReads=0;
    assert.throws(()=>qualifiedLinearCandidate(path=>{
        const response=f.api(path);
        return path.endsWith('/actions/runs/1234') && ++runReads===2?{...response,run_attempt:2}:response;
    },f.repository,sha),/raced/);
    let jobReads=0;
    assert.throws(()=>qualifiedLinearCandidate(path=>{
        const response=f.api(path);
        return path.includes('/jobs?') && ++jobReads===2?{...response,jobs:response.jobs.map(job=>({...job,conclusion:'failure'}))}:response;
    },f.repository,sha),/raced/);
    assert.throws(()=>qualifiedLinearCandidate(f.api,f.repository,'HEAD'),/identity/);
    assert.equal(f.state.writes.length,0);
}
{
    const f=linearFixture();
    f.state.developSha=f.reconciliation.result_sha;
    assert.equal(inspectSync(request,f.repository,f).state,'RECONCILED_EQUIVALENT');
    assert.equal(f.state.writes.length,0);
    f.state.ci=false;
    assert.equal(inspectSync(request,f.repository,f).state,'SYNC_PENDING');
    f.state.ci=true;f.state.redJob='Required Chromium fixtures';
    assert.equal(qualifiedLinearCandidate(f.api,f.repository,f.reconciliation.result_sha),null);
    f.state.redJob=null;f.state.changed=['app/services/lost-release.php'];
    assert.throws(()=>inspectSync(request,f.repository,f),/lost released or parallel changes/);
    f.state.changed=[];f.state.parents.push(f.record.candidate_sha);
    assert.throws(()=>inspectSync(request,f.repository,f),/exactly the observed develop parent/);
    f.state.parents.pop();f.state.ownerRun=false;
    assert.equal(inspectSync(request,f.repository,f).state,'SYNC_PENDING');
    f.state.ownerRun=true;f.state.protected=false;
    assert.equal(inspectSync(request,f.repository,f).state,'SYNC_BLOCKED');
    assert.equal(f.state.writes.length,0);
}
{
    const f=linearFixture();
    f.state.developSha=f.record.candidate_sha;
    f.reconciliation={...f.reconciliation,state:'RECONCILED_FF',result_sha:f.record.candidate_sha,result_tree:f.record.final_tree,
        develop_base_sha:f.record.selected_develop_sha};
    assert.equal(inspectSync(request,f.repository,f).state,'RECONCILED_FF');
    assert.equal(isAncestor(f.api,f.repository,f.record.final_main_sha,f.state.developSha),false,
        'Published main merge need not be in develop ancestry.');
    f.state.newMerges=true;
    assert.throws(()=>inspectSync(request,f.repository,f),/without new merge commits/);
    f.state.newMerges=false;
    f.reconciliation.result_tree='9'.repeat(40);
    assert.equal(inspectSync(request,f.repository,f).state,'SYNC_BLOCKED');
    assert.throws(()=>isAncestor(f.api,f.repository,'HEAD',f.state.developSha),/BLOCKED/);
}
{
    const f=linearFixture();delete f.reconciliation;
    assert.equal(inspectSync(request,f.repository,f).state,'SYNC_PENDING');
    assert.equal(inspectSync(request,f.repository,f).state,'SYNC_PENDING');
    assert.equal(f.state.writes.length,0,'Historical inspection never creates branches, dispatches or publication assets.');
    assert.equal(f.state.gitWrites.length,0);
    f.state.conflicts=['app/services/conflict.php'];
    assert.equal(inspectSync(request,f.repository,f).state,'SYNC_BLOCKED');
    assert.equal(f.state.writes.length,0);
}
{
    const f=linearFixture();f.state.conflicts=['app/services/conflict.php'];
    assert.throws(()=>verifyLinearContent(f.record.selected_develop_sha,f.reconciliation.develop_base_sha,
        f.record.candidate_sha,f.reconciliation.result_sha,f.git),/explicit decisions/);
    const path=f.state.conflicts[0];
    const decisions=[{path,base:path+'\0',develop:path+'\0',release:path+'\0',result:path+'\0',
        decision:'Keep both independently reviewed changes',review:'Owner acceptance #101'}];
    assert.doesNotThrow(()=>verifyLinearContent(f.record.selected_develop_sha,f.reconciliation.develop_base_sha,
        f.record.candidate_sha,f.reconciliation.result_sha,f.git,decisions));
    decisions[0].result='forged';
    assert.throws(()=>verifyLinearContent(f.record.selected_develop_sha,f.reconciliation.develop_base_sha,
        f.record.candidate_sha,f.reconciliation.result_sha,f.git,decisions),/exact blob/);
}
process.stdout.write('PASS linear FF/equivalence, whole-tree preservation, conflict, owner, race and exact CI contracts\n');

// The next release may select L without Q or the published main merge in its ancestry.
{
    const f=linearFixture();
    const predecessor={previous_release_candidate_sha:f.record.candidate_sha,
        previous_stable_tag:'v_'+f.record.version,previous_stable_sha:f.record.final_main_sha};
    const call=path=>{
        const response=f.api(path);
        return path.includes('/releases/tags/') ? {...response,assets:[...response.assets,{name:'release-reconciliation.json'}]} : response;
    };
    assert.doesNotThrow(()=>verifyPredecessorLineage(call,f.repository,predecessor,f.state.resultSha,f));
    const unbound=path=>{
        const response=call(path);
        return path.includes('/check-runs?') ? {...response,check_runs:response.check_runs.map(check=>({...check,external_id:''}))} : response;
    };
    assert.equal(qualifiedLinearCandidate(unbound,f.repository,f.state.resultSha),null);
    f.state.ci=false;
    assert.throws(()=>verifyPredecessorLineage(call,f.repository,predecessor,f.state.resultSha,f),/BLOCKED/);
    f.state.ci=true;f.reconciliation.repository='foreign/gallery';
    assert.throws(()=>verifyPredecessorLineage(call,f.repository,predecessor,f.state.resultSha,f),/not retained/);
    f.reconciliation.repository=f.repository;f.reconciliation.result_tree='9'.repeat(40);
    assert.throws(()=>verifyPredecessorLineage(call,f.repository,predecessor,f.state.resultSha,f),/content evidence differs/);
}
