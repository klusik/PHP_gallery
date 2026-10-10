/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/release_completion_handoff_test.mjs
 * Module Type: Regression Test
 * Purpose: Exercise automatic first-release dispatch, compatible Auto-merge metadata and interrupted completion.
 * Responsibilities:
 *   - Bind dispatch to Q and the existing default-branch workflow registration
 *   - Refuse foreign/stale/red PRs and unsafe existing Auto-merge methods without writes
 *   - Observe approval and real Git merge/tag/FF with bounded retry and continuation
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {startNewRelease} from '../.github/scripts/start-new-release.mjs';
import {openQualifiedPullRequest,completeRelease} from '../.github/scripts/release-completion.mjs';
import {dispatchCompletion,retryDispatch,observeApproval,retryCompletion} from '../.github/scripts/release-completion-handoff.mjs';
import {requireOwnerRuleset} from '../.github/scripts/release-owner-authorization.mjs';
import {lifecycleFixture} from './support/release_lifecycle_fixture.mjs';

const f=lifecycleFixture();
try {
    const record=f.prepare(startNewRelease({version:'1.2.1',selected:f.selected},f.repository,f));
    const pr=openQualifiedPullRequest(record,f.repository,'Reviewed release notes',f);
    let active=false,registered=true,pages=false;
    const api=(path,method='GET',payload=null)=>{
        if (path==='repos/'+f.repository) return {allow_auto_merge:true,allow_merge_commit:true};
        if (path.endsWith('/actions/workflows/release-promotion.yml')) return {
            path:'.github/workflows/release-promotion.yml',state:registered?'active':'disabled_manually'};
        if (path.includes('/actions/workflows/release-promotion.yml/runs?')) {
            const observer={id:777,path:'.github/workflows/release-promotion.yml',event:'workflow_dispatch',
                status:'in_progress',head_sha:record.candidate_sha,head_branch:record.branch};
            if (pages) return {total_count:101,workflow_runs:path.endsWith('&page=2')?[observer]:Array.from({length:100},(_,index)=>({...observer,id:index+1,status:'completed'}))};
            return {total_count:active?1:0,workflow_runs:active?[observer]:[]};
        }
        if (path.endsWith('/actions/workflows/release-promotion.yml/dispatches')) {
            assert.equal(method,'POST');assert.equal(payload.ref,record.branch);
            assert.deepEqual(payload.inputs,{mode:'complete',pr_number:String(pr.number),candidate_sha:record.candidate_sha,
                qualification_run:record.run_id,release_branch:record.branch});
            f.state.writes.push({path,method,payload});active=true;return null;
        }
        return f.api(path,method,payload);
    };
    const adapters={...f,api};
    assert.equal(dispatchCompletion(record,f.repository,pr.number,api),'DISPATCHED');
    let writes=f.state.writes.length;
    assert.equal(dispatchCompletion(record,f.repository,pr.number,api),'ALREADY_RUNNING');
    assert.equal(f.state.writes.length,writes,'Repeated handoff cannot create an observed duplicate worker.');
    pages=true;
    assert.equal(dispatchCompletion(record,f.repository,pr.number,api),'ALREADY_RUNNING','Active observers on later pages prevent duplicates.');
    pages=false;
    assert.equal(dispatchCompletion(record,f.repository,pr.number,api,'777'),'DISPATCHED','A pending worker continues without waiting for a new review event.');
    registered=false;
    assert.throws(()=>dispatchCompletion(record,f.repository,pr.number,api),/registered/);
    registered=true;
    f.state.ci=false;
    writes=f.state.writes.length;
    assert.throws(()=>dispatchCompletion(record,f.repository,pr.number,api),/BLOCKED/);
    assert.equal(f.state.writes.length,writes,'Red qualification cannot dispatch a release.');
    f.state.ci=true;
    for (const patch of [{user:{login:'fixture'}},{head:{...f.state.pr.head,sha:'a'.repeat(40)}},
        {head:{...f.state.pr.head,repo:{full_name:'intruder/gallery'}}},
        {base:{...f.state.pr.base,ref:'develop'}},
        {auto_merge:{merge_method:'squash',enabled_by:{login:'github-actions[bot]'}}}]) {
        const saved=f.state.pr;f.state.pr={...saved,...patch};
        assert.throws(()=>dispatchCompletion(record,f.repository,pr.number,api),/BLOCKED/);
        assert.equal(f.state.writes.length,writes,'Foreign, stale and unsafe PRs are read-only refusals.');
        f.state.pr=saved;
    }
    // Both branches accept real installation-token redaction without inventing data,
    // while malformed/exposed bypass and changed effective protections still fail.
    for (const [branch,name,check] of [['main','Reviewed release promotion to main','Release qualification'],
        ['develop','Reviewed main-to-develop release reconciliation','Complete required CI matrix']]) {
        f.state.policyVisible=false;
        assert.equal(requireOwnerRuleset(api,f.repository,branch,name,check).bypass_inventory,'NOT_RETURNED_SERVER_ENFORCED');
        for (const change of [rule=>({...rule,bypass_actors:null}),rule=>({...rule,bypass_actors:[{actor_type:'RepositoryRole'}]}),
            rule=>({...rule,id:99}),rule=>({...rule,current_user_can_bypass:'always'}),
            rule=>({...rule,conditions:{ref_name:{include:['refs/heads/foreign'],exclude:[]}}})]) {
            assert.throws(()=>requireOwnerRuleset(path=>/\/rulesets\/\d+$/.test(path)?change(api(path)):api(path),f.repository,branch,name,check),/BLOCKED/);
        }
        assert.throws(()=>requireOwnerRuleset(path=>path.endsWith('/rules/branches/'+branch)
            ?api(path).filter(rule=>rule.type!=='non_fast_forward'):api(path),f.repository,branch,name,check),/BLOCKED/);
    }
    f.state.policyVisible=true;
    f.state.review=false;
    f.state.pr.auto_merge={merge_method:'merge',enabled_by:{login:'github-actions[bot]'}};
    writes=f.state.writes.length;
    let readFailures=1;
    const readDelays=[];
    assert.equal(await observeApproval(record,f.repository,pr.number,(...args)=>{
        if (readFailures-->0) throw new Error('Temporary API outage');
        return api(...args);
    },async ms=>{readDelays.push(ms);},1),'PENDING');
    assert.deepEqual(readDelays,[10000,60000],'A temporary approval read retries without another review event.');
    let dispatchFailures=1;
    const dispatchDelays=[];
    assert.equal(await retryDispatch(record,f.repository,pr.number,(...args)=>{
        if (dispatchFailures-->0) throw new Error('Temporary dispatch transport outage');
        return api(...args);
    },'',async ms=>{dispatchDelays.push(ms);}),'ALREADY_RUNNING');
    assert.deepEqual(dispatchDelays,[10000]);
    assert.equal(await observeApproval(record,f.repository,pr.number,api,async()=>{},2),'PENDING');
    assert.equal(f.state.writes.length,writes,'Unapproved polling is read-only.');
    await assert.rejects(observeApproval(record,f.repository,pr.number,path=>path.includes('/reviews?')
        ?Array.from({length:100},()=>({})):api(path),async()=>{},1),/incomplete/);
    assert.throws(()=>dispatchCompletion(record,f.repository,pr.number,path=>path.includes('/runs?')
        ?{total_count:-1,workflow_runs:[]}:api(path)),/inventory/);
    assert.throws(()=>completeRelease(record,f.repository,pr.number,adapters),/approval/);
    let probes=0;
    assert.equal(await observeApproval(record,f.repository,pr.number,api,async()=>{probes++;f.state.review=true;},2),'READY');
    assert.equal(probes,1,'One owner approval is sufficient even after completion has started.');
    // Native Auto-merge has already produced the actual Git M before the worker
    // resumes. Completion must create only the missing immutable tag and FF.
    api('repos/'+f.repository+'/pulls/'+pr.number+'/merge','PUT',{sha:record.candidate_sha,merge_method:'merge'});
    writes=f.state.writes.length;
    assert.equal(await observeApproval(record,f.repository,pr.number,api,async()=>{},1),'READY');
    f.state.tagFailOnce=true;
    const delays=[];
    const result=await retryCompletion(record,f.repository,pr.number,adapters,async ms=>{delays.push(ms);});
    assert.deepEqual(delays,[10000]);
    assert.equal(result.synchronization,'RECONCILED_FF');
    assert.equal(f.state.writes.slice(writes).filter(write=>write.path.endsWith('/merge')).length,0,'Completion observes native M without a second merge.');
    writes=f.state.writes.length;
    assert.equal(openQualifiedPullRequest(record,f.repository,'Reviewed release notes',adapters).number,pr.number,'A merged existing PR is reused on proposal recovery.');
    await retryCompletion(record,f.repository,pr.number,adapters,async()=>{});
    assert.equal(f.state.writes.length,writes,'Correct existing M/tag/develop cause no duplicate writes.');
    assert.equal(result.evidence.main_policy.bypass_inventory,'VISIBLE_EMPTY');
} finally {f.cleanup();}

const workflow=readFileSync(new URL('../.github/workflows/release-promotion.yml',import.meta.url),'utf8');
assert.match(workflow,/workflow_dispatch:/);
assert.match(workflow,/REQUESTED_RUN/);
assert.match(workflow,/probe-child/);
assert.match(workflow,/ref: \$\{\{ env.CANDIDATE_SHA \}\}/);
assert.doesNotMatch(workflow,/environment:|gh release (create|edit)|refs\/heads\/main['"]?\s*$/m);
process.stdout.write('PASS qualified automatic dispatch, safe Auto-merge, one approval, native M, retry and idempotent tag/FF\n');
