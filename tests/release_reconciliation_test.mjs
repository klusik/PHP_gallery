/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/release_reconciliation_test.mjs
 * Module Type: Regression Test
 * Purpose: Prove published-main reconciliation remains reviewed, exact-SHA qualified and fail-closed.
 * Responsibilities:
 *   - Simulate immutable GitHub publication, ancestry, branch protections and hosted jobs
 *   - Reject incomplete/moved refs, unreviewed proposals and unqualified merges
 *   - Verify repeatable proposal and exact reconciled status without external writes
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {
    validateSyncRequest,syncBranchName,isAncestor,inspectSync,proposeSync,verifySyncWriteControls,
    qualifiedDevelopMerge,publishedContentSurvived,hasEffectiveSyncApproval
} from '../.github/scripts/release-reconciliation.mjs';

const owner = 'fixture/repo';
const tagSha = 'a'.repeat(40);
const developStart = 'b'.repeat(40);
const mergeSha = 'c'.repeat(40);
const treeSha = 'd'.repeat(40);
const candidateSha = 'e'.repeat(40);
const request = {branch:'release/v_1.2.3',mode:'plan',manualReview:''};
const accepted = {...request,mode:'propose',manualReview:'Reviewed acceptance #42'};
const syncRef = syncBranchName('1.2.3',tagSha);
const publishedMetadata = {'1.2.3':{released_at:'2026-10-09 19:00:00',released_label:'9 October 2026, 19:00',tag:'v_1.2.3'}};
const publishedNotes = '# Patch notes\n\n## Version 1.2.3\n\n### Highlights\n\n- Reviewed release.\n';
const evidence = {version:'1.2.3',branch:request.branch,run_id:'123',candidate_sha:candidateSha,
    final_main_sha:tagSha,final_tree:treeSha};
const environment = {protection_rules:[{type:'required_reviewers',prevent_self_review:false,
    reviewers:[{type:'User',reviewer:{login:'fixture'}}]}],can_admins_bypass:false,
    deployment_branch_policy:{custom_branch_policies:true,protected_branches:false}};
const reviewContext = {GITHUB_EVENT_NAME:'workflow_dispatch',GITHUB_REF:'refs/heads/main',GITHUB_ACTOR:'fixture'};
const protectedRules = [{type:'pull_request',parameters:{required_approving_review_count:1,
    dismiss_stale_reviews_on_push:true,require_last_push_approval:false,
    required_review_thread_resolution:true,allowed_merge_methods:['merge']}},{type:'deletion'},{type:'non_fast_forward'},
    {type:'required_status_checks',parameters:{strict_required_status_checks_policy:true,
        required_status_checks:[{context:'Complete required CI matrix'}]}}];
const ruleset = {id:24808873,name:'Reviewed main-to-develop release reconciliation',target:'branch',
    enforcement:'active',source:owner,current_user_can_bypass:'never',
    bypass_actors:[],conditions:{ref_name:{include:['refs/heads/develop'],exclude:[]}},
    rules:structuredClone(protectedRules)};
const mandatory = [
    'Read-only generated-state and source preflight',
    'Positive production package (ubuntu-24.04)','Positive production package (windows-2025)',
    'Positive production package (macos-latest)',
    'PHP 8.3 workflows (mysql:8.4, Unicode)','PHP 8.3 workflows (mariadb:10.11, Unicode)',
    'PHP 8.5 workflows (mariadb:11.4, Unicode)','PHP 8.1 source (unicode)',
    'PHP 8.5 source (ascii)','Required Chromium fixtures','Complete required CI matrix'
];

/** Build a mutable inert GitHub state machine for synchronization verification.
 * @returns {{api:function(string,string=,object=):object,state:object,evidence:object,context:object}} Independent test-controlled GitHub adapter and expected identities.
 */
function fixture() {
    const state = {developSha:developStart, mainSha:tagSha, published:true, actualTag:tagSha,
        rules:structuredClone(protectedRules), ruleset:structuredClone(ruleset), syncSha:null, pr:null, run:'none',
        redJob:null, review:true, dismissedReview:false,oldReviewSha:false,
        mergeParents:true,alteredMetadata:false,alteredNotes:false,
        environment:structuredClone(environment), writes:[]};
    const api = (path,method = 'GET',payload = null) => {
        if (method !== 'GET') state.writes.push({path,method,payload});
        if (path.endsWith('/git/ref/tags/v_1.2.3')) return {object:{type:'commit',sha:state.actualTag}};
        if (path.endsWith('/releases/tags/v_1.2.3')) return {tag_name:'v_1.2.3',
            draft:!state.published,prerelease:false,assets:[{name:'release-evidence.json'}]};
        if (path.endsWith('/commits/' + state.actualTag)) return {commit:{tree:{sha:treeSha}}};
        if (path.endsWith('/commits/' + mergeSha)) return {parents:state.mergeParents
            ? [{sha:developStart},{sha:tagSha}] : [{sha:developStart}]};
        if (path.endsWith('/git/ref/heads/main')) return {object:{sha:state.mainSha}};
        if (path.endsWith('/git/ref/heads/develop')) return {object:{sha:state.developSha}};
        if (path.includes('/git/matching-refs/heads/')) return state.syncSha ?
            [{ref:'refs/heads/' + syncRef,object:{type:'commit',sha:state.syncSha}}] : [];
        if (path.includes('/pulls?state=all&base=develop')) return state.pr ? [structuredClone(state.pr)] : [];
        if (path.includes('/pulls/55/reviews?per_page=100')) {
            if (!state.review) return [];
            const valid={id:72,state:'APPROVED',user:{login:'fixture'},
                commit_id:state.oldReviewSha ? developStart : tagSha,submitted_at:'2026-10-09T19:00:00Z'};
            return state.dismissedReview ? [valid,{...valid,state:'DISMISSED',submitted_at:'2026-10-09T19:15:00Z'}] : [valid];
        }
        if (path.includes('/contents/release-metadata.json?ref=') || path.includes('/contents/PATCH_NOTES.md?ref=')) {
            const isMetadata=path.includes('/release-metadata.json?ref=');
            const isMerge=path.endsWith('ref=' + mergeSha);
            const data=isMetadata ? structuredClone(publishedMetadata) : publishedNotes;
            if (isMetadata && isMerge && state.alteredMetadata) data['1.2.3'].released_label='Forged label';
            const bytes=Buffer.from(isMetadata ? JSON.stringify(data) : data + (isMerge && state.alteredNotes ? '\nUnauthorized note rewrite.' : ''));
            return {type:'file',encoding:'base64',content:bytes.toString('base64'),size:bytes.length};
        }
        if (path.endsWith('/rules/branches/develop')) return structuredClone(state.rules);
        if (path.endsWith('/rulesets')) return [{id:24808873,name:state.ruleset.name,
            enforcement:state.ruleset.enforcement}];
        if (path.endsWith('/rulesets/24808873')) return structuredClone(state.ruleset);
        if (path.includes('/compare/')) {
            const [,left,right] = /\/compare\/([a-f0-9]{40})\.\.\.([a-f0-9]{40})$/.exec(path) ?? [];
            if (left === tagSha && right === developStart) return {behind_by:1};
            if (left === tagSha && (right === state.mainSha || right === mergeSha
                || (state.run === 'green' && right === state.developSha))) return {behind_by:0};
            if (left === mergeSha && right === state.developSha && state.run === 'green') return {behind_by:0};
            if (left === developStart && right === mergeSha) return {behind_by:0};
            return {behind_by:1};
        }
        if (path.includes('/actions/workflows/gallery-workflows.yml/runs?')) {
            return {total_count:1,workflow_runs:[{id:1234,path:'.github/workflows/gallery-workflows.yml',
                event:'push',head_branch:'develop',head_sha:mergeSha,status:'completed',
                conclusion:state.run === 'green' ? 'success':'failure',run_attempt:1,html_url:'https://github.com/fixture/repo/actions/runs/1234'}]};
        }
        if (path.includes('/actions/runs/1234/attempts/1/jobs?')) return {total_count:mandatory.length,
            jobs:mandatory.map(name => ({name,status:'completed',
                conclusion:state.redJob === name ? 'failure' : 'success'}))};
        if (path.includes('/collaborators/fixture/permission')) return {permission:'admin'};
        if (path.endsWith('/environments/release-reconciliation')) return structuredClone(state.environment);
        if (path.endsWith('/environments/release-reconciliation/deployment-branch-policies?per_page=100'))
            return {total_count:1,branch_policies:[{type:'branch',name:'main'}]};
        if (path.endsWith('/git/refs') && method === 'POST') {
            assert.equal(payload.ref,'refs/heads/' + syncRef);
            assert.equal(payload.sha,tagSha);
            state.syncSha = payload.sha;
            return {ref:payload.ref,object:{sha:payload.sha}};
        }
        if (path.endsWith('/pulls') && method === 'POST') {
            assert.equal(payload.head,syncRef);
            assert.equal(payload.base,'develop');
            state.pr = {number:55,head:{ref:syncRef,sha:tagSha,repo:{full_name:owner}},
                base:{ref:'develop'},user:{login:'github-actions[bot]'},state:'open',merged_at:null,merge_commit_sha:null};
            return structuredClone(state.pr);
        }
        throw new Error('Unexpected GitHub fixture path: ' + method + ' ' + path);
    };
    return {api,state,evidence,context:reviewContext};
}

assert.equal(validateSyncRequest(request),'1.2.3');
assert.equal(syncRef,'sync/release/v_1.2.3-' + tagSha.slice(0,12));
for (const branch of ['main','develop','release/v_01.2.3','release/v_1.2.3;rm']) {
    assert.throws(() => validateSyncRequest({...request,branch}),/BLOCKED/);
}
assert.throws(() => validateSyncRequest({...request,mode:'publish'}),/BLOCKED/);
assert.throws(() => validateSyncRequest({...request,mode:'propose'}),/human|acceptance/i);
assert.throws(() => syncBranchName('1.2.3','main'),/BLOCKED/);
assert.equal(hasEffectiveSyncApproval([{id:72,state:'APPROVED',user:{login:'fixture'},commit_id:tagSha,
    submitted_at:'2026-10-09T19:00:00Z'}],{user:{login:'fixture'},
    merged_at:'2026-10-09T20:00:00Z'},'fixture',tagSha),null,
    'Owner-authored PR cannot qualify for sole-owner approval.');
{
    const f = fixture();
    assert.equal(inspectSync(request,owner,f).state,'SYNC_PENDING');
    assert.equal(f.state.writes.length,0,'Read-only plan must not modify server state.');
    assert.equal(isAncestor(f.api,owner,tagSha,tagSha),true);
    assert.equal(isAncestor(f.api,owner,tagSha,developStart),false);
    assert.throws(() => isAncestor(f.api,owner,'HEAD',developStart),/BLOCKED/);
    assert.throws(() => verifySyncWriteControls(owner,f.api,{...reviewContext,GITHUB_REF:'refs/heads/develop'}),/BLOCKED/);
    f.state.environment.protection_rules[0].prevent_self_review=true;
    assert.throws(() => verifySyncWriteControls(owner,f.api,reviewContext),/BLOCKED/);
    f.state.environment=structuredClone(environment);
    f.state.rules=[];
    assert.equal(inspectSync(request,owner,f).state,'SYNC_BLOCKED');
    assert.throws(() => proposeSync(accepted,owner,f),/BLOCKED/);
    assert.equal(f.state.writes.length,0);
}
{
    const f = fixture();
    const proposed=proposeSync(accepted,owner,f);
    assert.equal(proposed.state,'SYNC_PENDING');
    assert.equal(f.state.syncSha,tagSha);
    assert.equal(f.state.pr.base.ref,'develop');
    assert.equal(f.state.pr.user.login,'github-actions[bot]');
    assert.equal(f.state.writes.filter(w=>w.method==='POST').length,2);
    const originalWrites=f.state.writes.length;
    assert.equal(inspectSync(request,owner,f).state,'SYNC_PENDING');
    proposeSync(accepted,owner,f);
    assert.equal(f.state.writes.length,originalWrites,'Repeat proposal must not duplicate PR/branch.');
    f.state.pr.user.login='fixture';
    assert.equal(inspectSync(request,owner,f).state,'SYNC_BLOCKED',
        'Owner-authored sync PR cannot be approved by the same human.');
    f.state.pr.user.login='github-actions[bot]';
    f.state.developSha=mergeSha;
    f.state.pr.merged_at='2026-10-09T20:00:00Z';
    f.state.pr.state='closed';
    f.state.pr.merge_commit_sha=mergeSha;
    assert.equal(inspectSync(request,owner,f).state,'SYNC_PENDING','Red hosted CI cannot finalize sync.');
    f.state.review=false;
    assert.equal(inspectSync(request,owner,f).state,'SYNC_BLOCKED','Missing independent approval is not a completed release.');
    f.state.review=true;
    f.state.mergeParents=false;
    assert.equal(inspectSync(request,owner,f).state,'SYNC_BLOCKED','Squash/no-full-merge cannot discard unrelated develop lineage.');
    f.state.mergeParents=true;
    f.state.run='green';
    f.state.redJob='PHP 8.3 workflows (mysql:8.4, Unicode)';
    assert.equal(qualifiedDevelopMerge(f.api,owner,mergeSha),null);
    assert.equal(inspectSync(request,owner,f).state,'SYNC_PENDING','Red required job is not accepted.');
    f.state.redJob=null;
    assert.equal(publishedContentSurvived(f.api,owner,'1.2.3',tagSha,mergeSha),true);
    f.state.alteredMetadata=true;
    assert.equal(inspectSync(request,owner,f).state,'SYNC_BLOCKED','Historical release metadata must be immutable.');
    f.state.alteredMetadata=false;
    f.state.alteredNotes=true;
    assert.equal(inspectSync(request,owner,f).state,'SYNC_BLOCKED','Published patch notes must survive exact reconciliation.');
    f.state.alteredNotes=false;
    f.state.dismissedReview=true;
    assert.equal(inspectSync(request,owner,f).state,'SYNC_BLOCKED','Dismissed approval is not an effective reviewer.');
    f.state.dismissedReview=false;
    f.state.oldReviewSha=true;
    assert.equal(inspectSync(request,owner,f).state,'SYNC_BLOCKED','Approval of a different head SHA is stale.');
    f.state.oldReviewSha=false;
    const reconciled=inspectSync(request,owner,f);
    assert.equal(reconciled.state,'RECONCILED');
    assert.equal(reconciled.ownerApproval?.reviewer,'fixture');
    assert.equal(reconciled.ownerApproval?.commit_sha,tagSha);
    assert.ok(reconciled.qualificationRun?.endsWith('/1234'));
    assert.equal(f.state.writes.length,originalWrites);
    f.state.developSha=developStart;
    assert.equal(inspectSync(request,owner,f).state,'SYNC_BLOCKED','Merged PR missing from develop ancestry is not complete.');
}
{
    const f = fixture();
    f.state.published=false;
    assert.throws(() => inspectSync(request,owner,f),/not published/);
    f.state.published=true;
    f.state.actualTag=developStart;
    assert.throws(() => inspectSync(request,owner,f),/evidence disagree|missing/);
    f.state.actualTag=tagSha;
    f.state.mainSha=developStart;
    assert.throws(() => inspectSync(request,owner,f),/main ancestry/);
    f.state.mainSha=tagSha;
    f.state.syncSha=developStart;
    assert.throws(() => inspectSync(request,owner,f),/lost immutable main ancestry/);
}
process.stdout.write('PASS release reconciliation publication, review, ancestry and CI contracts\n');
