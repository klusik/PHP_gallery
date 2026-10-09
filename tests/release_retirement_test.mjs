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
import {validateRetirementRequest,planRetirement,retireBranch,verifyRetirementControls} from '../.github/scripts/release-retirement.mjs';

const repository='fixture/gallery';
const main='a'.repeat(40);
const priorDevelop='b'.repeat(40);
const mergedDevelop='c'.repeat(40);
const tree='d'.repeat(40);
const candidate='e'.repeat(40);
const branch='release/v_1.2.3';
const active='sync/release/v_1.2.3-'+main.slice(0,12);
const releaseMetadata={'1.2.3':{released_at:'2026-10-09 19:00:00',released_label:'9 October 2026, 19:00',tag:'v_1.2.3'}};
const releaseNotes='# Patch notes\n\n## Version 1.2.3\n\n### Highlights\n\n- Reviewed release.\n';
const request={branch,mode:'plan',manualReview:''};
const approved={...request,mode:'retire',manualReview:'Independently authorized cleanup ticket #17'};
const mandatory=['Read-only generated-state and source preflight','Positive production package (ubuntu-24.04)',
    'Positive production package (windows-2025)','Positive production package (macos-latest)',
    'PHP 8.3 workflows (mysql:8.4, Unicode)','PHP 8.3 workflows (mariadb:10.11, Unicode)',
    'PHP 8.5 workflows (mariadb:11.4, Unicode)','PHP 8.1 source (unicode)','PHP 8.5 source (ascii)',
    'Required Chromium fixtures','Complete required CI matrix'];
const developRules=[{type:'deletion'},{type:'non_fast_forward'},
    {type:'pull_request',parameters:{required_approving_review_count:1,dismiss_stale_reviews_on_push:true,
        require_last_push_approval:false,required_review_thread_resolution:true,
        allowed_merge_methods:['merge']}},
    {type:'required_status_checks',parameters:{strict_required_status_checks_policy:true,
        required_status_checks:[{context:'Complete required CI matrix'}]}}];
const developRuleset={id:24808873,name:'Reviewed main-to-develop release reconciliation',
    enforcement:'active',target:'branch',source:repository,current_user_can_bypass:'never',
    bypass_actors:[],conditions:{ref_name:{include:['refs/heads/develop'],exclude:[]}},
    rules:developRules};

/** Build a fake GitHub server with exact release and successful merge history.
 * @returns {{api:function(string,string=,object=):object,git:function(string,string[]):string,state:object,evidence:object,context:object}} Isolated mutable fixture.
 */
function fixture() {
    const state={releaseSha:candidate,openPr:false,openBasePr:false,active:false,brokenApproval:false,brokenCI:false,
        envAllowed:true,leaseFails:false,gitWrites:[]};
    const evidence={version:'1.2.3',branch,run_id:'123',candidate_sha:candidate,
        final_main_sha:main,final_tree:tree};
    const api=path=>{
        if (path.endsWith('/git/ref/tags/v_1.2.3')) return {object:{type:'commit',sha:main}};
        if (path.endsWith('/releases/tags/v_1.2.3')) return {tag_name:'v_1.2.3',draft:false,
            prerelease:false,assets:[{name:'release-evidence.json'}]};
        if (path.endsWith('/commits/'+main)) return {commit:{tree:{sha:tree}}};
        if (path.endsWith('/git/ref/heads/main')) return {object:{sha:main}};
        if (path.endsWith('/git/ref/heads/develop')) return {object:{sha:mergedDevelop}};
        if (path.includes('/git/matching-refs/heads/')) {
            if (path.endsWith('/'+branch)) return state.releaseSha ?
                [{ref:'refs/heads/'+branch,object:{type:'commit',sha:state.releaseSha}}] : [];
            if (path.endsWith('/'+active)) return [];
        }
        if (path.includes('/pulls?state=all&base=develop')) return [{number:55,
            head:{ref:active,sha:main,repo:{full_name:repository}},base:{ref:'develop'},
            user:{login:'github-actions[bot]'},state:'closed',merged_at:'2026-10-09T20:00:00Z',
            merge_commit_sha:mergedDevelop}];
        if (path.includes('/pulls?state=open&head=')) return state.openPr ? [{number:8}] : [];
        if (path.includes('/pulls?state=open&base=')) return state.openBasePr ? [{number:9}] : [];
        if (path.endsWith('/rules/branches/develop')) return developRules;
        if (path.endsWith('/rulesets')) return [{id:24808873,name:developRuleset.name,
            enforcement:'active'}];
        if (path.endsWith('/rulesets/24808873')) return developRuleset;
        if (path.endsWith('/commits/'+mergedDevelop)) return {parents:[{sha:priorDevelop},{sha:main}]};
        if (path.includes('/pulls/55/reviews?')) return state.brokenApproval ? [] :
            [{id:91,state:'APPROVED',user:{login:'fixture'},commit_id:main,
                submitted_at:'2026-10-09T19:00:00Z'}];
        if (path.includes('/contents/release-metadata.json?ref=') || path.includes('/contents/PATCH_NOTES.md?ref=')) {
            const value=path.includes('release-metadata.json') ? JSON.stringify(releaseMetadata) : releaseNotes;
            const bytes=Buffer.from(value);
            return {type:'file',encoding:'base64',content:bytes.toString('base64'),size:bytes.length};
        }
        if (path.includes('/compare/')) return {behind_by:0};
        if (path.includes('/actions/workflows/gallery-workflows.yml/runs?')) return {total_count:1,workflow_runs:[
            {path:'.github/workflows/gallery-workflows.yml',event:'push',head_branch:'develop',
                head_sha:mergedDevelop,status:'completed',conclusion:state.brokenCI?'failure':'success',
                run_attempt:1,id:1234,html_url:'https://github.com/fixture/gallery/actions/runs/1234'}]};
        if (path.includes('/actions/runs/1234/attempts/1/jobs?')) return {total_count:mandatory.length,
            jobs:mandatory.map(name=>({name,status:'completed',conclusion:'success'}))};
        if (path.includes('/actions/workflows/release-qualification.yml/runs?')
            || path.includes('/actions/workflows/release-promotion.yml/runs?')
            || path.includes('/actions/workflows/release-reconciliation.yml/runs?')) {
            return {total_count:state.active?1:0,workflow_runs:state.active?[{
                head_branch:branch,status:'in_progress'}]:[]};
        }
        if (path.includes('/collaborators/fixture/permission')) return {permission:'admin'};
        if (path.endsWith('/environments/release-retirement')) return {
            protection_rules:[{type:'required_reviewers',prevent_self_review:!state.envAllowed,
                reviewers:[{type:'User',reviewer:{login:'fixture'}}]}],can_admins_bypass:false,
            deployment_branch_policy:{custom_branch_policies:true,protected_branches:false}};
        if (path.includes('/environments/release-retirement/deployment-branch-policies')) return {
            total_count:1,branch_policies:[{name:'main'}]};
        assert.fail('Unexpected GitHub fixture API: '+path);
    };
    const git=(executable,args)=>{
        state.gitWrites.push({executable,args});
        if (executable==='gh' && args[0]==='auth' && args[1]==='setup-git') return '';
        if (executable==='git' && args[0]==='push') {
            if (state.leaseFails) throw new Error('remote ref lease failed');
            assert.ok(args.includes('--force-with-lease=refs/heads/'+branch+':'+candidate));
            assert.equal(args.at(-1),':refs/heads/'+branch);
            assert.equal(state.releaseSha,candidate);
            state.releaseSha=null;
            return '';
        }
        assert.fail('Unexpected Git fixture command: '+executable+' '+args.join(' '));
    };
    const context={GITHUB_EVENT_NAME:'workflow_dispatch',GITHUB_REF:'refs/heads/main',GITHUB_ACTOR:'fixture'};
    return {state,api,git,context,evidence};
}
assert.doesNotThrow(()=>validateRetirementRequest(request));
for (const invalid of [{...request,branch:'develop'},{...request,branch:'release/v_1.2.03'},
    {...request,mode:'retire'},{...request,mode:'delete-all'}]) {
    assert.throws(()=>validateRetirementRequest(invalid),/BLOCKED/);
}
{
    const f=fixture();
    assert.equal(planRetirement(request,repository,f).state,'ELIGIBLE');
    assert.equal(f.state.gitWrites.length,0,'Plan must be entirely read only.');
    f.state.openPr=true;
    assert.equal(planRetirement(request,repository,f).state,'BLOCKED');
    f.state.openPr=false;
    f.state.openBasePr=true;
    assert.equal(planRetirement(request,repository,f).state,'BLOCKED','A PR targeting the release branch still depends on it.');
    f.state.openBasePr=false;
    f.state.active=true;
    assert.equal(planRetirement(request,repository,f).state,'BLOCKED');
    f.state.active=false;
    f.state.releaseSha=priorDevelop;
    assert.equal(planRetirement(request,repository,f).state,'BLOCKED');
    f.state.releaseSha=candidate;
    f.state.brokenApproval=true;
    assert.equal(planRetirement(request,repository,f).state,'BLOCKED');
    f.state.brokenApproval=false;
    f.state.brokenCI=true;
    assert.equal(planRetirement(request,repository,f).state,'BLOCKED');
    f.state.brokenCI=false;
    f.state.releaseSha=null;
    assert.equal(planRetirement(request,repository,f).state,'ALREADY_DELETED');
}
{
    const f=fixture();
    assert.throws(()=>verifyRetirementControls(repository,f.api,{...f.context,GITHUB_REF:'refs/heads/develop'}),/BLOCKED/);
    f.state.envAllowed=false;
    assert.throws(()=>retireBranch(approved,repository,f),/BLOCKED/);
    assert.equal(f.state.releaseSha,candidate);
    assert.equal(f.state.gitWrites.length,0);
    f.state.envAllowed=true;
    f.state.leaseFails=true;
    assert.throws(()=>retireBranch(approved,repository,f),/lease failed/);
    assert.equal(f.state.releaseSha,candidate);
    f.state.leaseFails=false;
    const retired=retireBranch(approved,repository,f);
    assert.equal(retired.state,'RETIRED');
    assert.equal(f.state.releaseSha,null);
    assert.equal(f.state.gitWrites.filter(w=>w.executable==='git').length,2,
        'Rejected stale-lease attempt and successful leased delete must both remain observable.');
    const priorWrites=f.state.gitWrites.length;
    assert.equal(retireBranch(approved,repository,f).state,'ALREADY_DELETED');
    assert.equal(f.state.gitWrites.length,priorWrites,'Idempotent cleanup must not repeat deletion.');
}
process.stdout.write('PASS reviewed release retirement, active operation guards and exact Git lease\n');
