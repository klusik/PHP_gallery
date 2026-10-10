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
