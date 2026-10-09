/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/release_retirement_test.mjs
 * Module Type: Regression Test
 * Purpose: Test optional release ref deletion is conditional, reviewed and compare-and-swap protected.
 * Responsibilities:
 *   - Refuse non-release or moved refs, active operations and incomplete reconciliation
 *   - Prove only a full published/merged/qualified exact release can be retired
 *   - Assert idempotent leased deletion without contacting a live GitHub repository
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {linearFixture} from './support/linear_release_fixture.mjs';
import {validateRetirementRequest,planRetirement,retireBranch,verifyRetirementControls} from '../.github/scripts/release-retirement.mjs';
const request={branch:'release/v_1.2.3',mode:'plan',manualReview:''};
const approved={...request,mode:'retire',manualReview:'Separate owner cleanup acceptance #101'};
for (const invalid of [{...request,branch:'develop'},{...request,branch:'release/v_1.2.03'},
    {...request,mode:'retire'},{...request,mode:'delete-all'}]) assert.throws(()=>validateRetirementRequest(invalid),/BLOCKED/);
{
    const f=linearFixture();f.state.developSha=f.reconciliation.result_sha;
    assert.equal(planRetirement(request,f.repository,f).state,'ELIGIBLE');
    assert.equal(f.state.gitWrites.length,0);
    for (const field of ['openPr','openBasePr','active']) {
        f.state[field]=true;assert.equal(planRetirement(request,f.repository,f).state,'BLOCKED');f.state[field]=false;
    }
    f.state.releaseSha='9'.repeat(40);assert.equal(planRetirement(request,f.repository,f).state,'BLOCKED');
    f.state.releaseSha=f.record.candidate_sha;f.state.ci=false;
    assert.equal(planRetirement(request,f.repository,f).state,'BLOCKED');
    f.state.ci=true;f.state.ownerRun=false;assert.equal(planRetirement(request,f.repository,f).state,'BLOCKED');
    f.state.ownerRun=true;
    assert.throws(()=>verifyRetirementControls(f.repository,f.api,{...f.context,GITHUB_REF:'refs/heads/develop'}),/BLOCKED/);
    const unapprovedEnvironment=(path,method,payload)=>{
        const response=f.api(path,method,payload);
        return path.endsWith('/environments/release-retirement') ? {...response,
            protection_rules:response.protection_rules.map(rule=>({...rule,prevent_self_review:true}))} : response;
    };
    assert.throws(()=>retireBranch(approved,f.repository,{...f,api:unapprovedEnvironment}),/BLOCKED/);
    assert.equal(f.state.releaseSha,f.record.candidate_sha);
    assert.equal(f.state.gitWrites.length,0,'An incompatible retirement approval gate cannot delete a ref.');
    f.state.leaseFails=true;assert.throws(()=>retireBranch(approved,f.repository,f),/lease failed/);
    assert.equal(f.state.releaseSha,f.record.candidate_sha);f.state.leaseFails=false;
    assert.equal(retireBranch(approved,f.repository,f).state,'RETIRED');
    assert.equal(f.state.releaseSha,null);
    const writes=f.state.gitWrites.length;
    assert.equal(retireBranch(approved,f.repository,f).state,'ALREADY_DELETED');
    assert.equal(f.state.gitWrites.length,writes);
    assert.ok(f.state.gitWrites.filter(write=>write.executable==='git').every(write=>
        write.args.includes('--force-with-lease=refs/heads/'+request.branch+':'+f.record.candidate_sha)
        && write.args.at(-1)===':refs/heads/'+request.branch));
}
{
    const f=linearFixture();f.state.developSha=f.record.candidate_sha;
    f.reconciliation={...f.reconciliation,state:'RECONCILED_FF',result_sha:f.record.candidate_sha,
        result_tree:f.record.final_tree,develop_base_sha:f.record.selected_develop_sha};
    assert.equal(planRetirement(request,f.repository,f).state,'ELIGIBLE');
    assert.equal(f.state.gitWrites.length,0,'Keeping a reconciled release branch remains a read-only choice.');
    f.state.ci=false;
    assert.equal(planRetirement(request,f.repository,f).state,'BLOCKED');
}
process.stdout.write('PASS linear reconciliation retirement prerequisite, active work and exact deletion lease\n');
