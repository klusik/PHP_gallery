/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/hosted_release_policy_test.mjs
 * Module Type: Regression Test
 * Purpose: Refuse stale, partial and unaudited hosted release promotion without network writes.
 * Responsibilities:
 *   - Exercise real qualification validators with immutable synthetic server records
 *   - Protect CI-first instructions, branch triggers and isolated release credentials
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {validateRequest,verifyQualification,verifyFinalContent,requireQualificationChecks,fetchReleases,fetchJobs} from '../.github/scripts/release-promotion.mjs';
import {requireOwnerDispatch,requireOwnerEnvironment,requireOwnerRuleset,requireBotPullRequest,effectiveOwnerReview} from '../.github/scripts/release-owner-authorization.mjs';
const read=path=>readFileSync(new URL('../'+path,import.meta.url),'utf8');
const candidate = 'a'.repeat(40);
const request = {runId:'123',candidate,branch:'release/v_1.2.3',mode:'plan',override:false,reason:'',acceptedFailures:[],manualReview:''};
const run = {path:'.github/workflows/release-qualification.yml',event:'push',head_branch:request.branch,status:'completed',conclusion:'success',run_attempt:1};
const record = {repository:'owner/gallery',branch:request.branch,candidate_sha:candidate,
    source_base:'b'.repeat(40),version:'1.2.3',run_id:'123',run_attempt:'1',ready:true,automated_result:'success',override:false,
    origin_commit_sha:'e'.repeat(40),selected_develop_sha:'f'.repeat(40),
    initial_main_sha:'9'.repeat(40),previous_stable_tag:'v_1.2.2',previous_stable_sha:'9'.repeat(40),
    previous_release_candidate_sha:'b'.repeat(40),previous_tag_object_sha:'9'.repeat(40),
    previous_tag_object_type:'commit',previous_tree_sha:'8'.repeat(40),predecessor_kind:'qualified',origin_blob_sha:'7'.repeat(40)};
const owners = ['Prepare release candidate','Read-only generated-state and source preflight',
    'Positive production package (ubuntu-24.04)','Positive production package (windows-2025)','Positive production package (macos-latest)',
    'PHP 8.3 workflows (mysql:8.4, Unicode)','PHP 8.3 workflows (mariadb:10.11, Unicode)','PHP 8.5 workflows (mariadb:11.4, Unicode)',
    'PHP 8.1 source (unicode)','PHP 8.5 source (ascii)','Required Chromium fixtures','Authoritative release audit','Complete required CI matrix','Release qualification gate'];
const jobs = owners.map(name => ({name,status:'completed',conclusion:'success',html_url:'https://github.com/owner/gallery/actions/runs/123'}));
assert.equal(validateRequest(request),'1.2.3');
verifyFinalContent(candidate,candidate,'b'.repeat(40),'b'.repeat(40));
assert.throws(() => verifyFinalContent('c'.repeat(40),candidate,'b'.repeat(40),'b'.repeat(40)),/main advanced/);
assert.throws(() => verifyFinalContent(candidate,candidate,'c'.repeat(40),'b'.repeat(40)),/tree differs/);
assert.deepEqual(verifyQualification(request,run,jobs,record,record.repository,candidate),[]);
for (const branch of ['main','develop','feature/example','release/v_01.2.3','release/v_1.2.3;command']) {
    assert.throws(() => validateRequest({...request,branch}),/BLOCKED/);
}
assert.throws(() => validateRequest({...request,candidate:'main'}),/BLOCKED/);
assert.throws(() => validateRequest({...request,mode:'publish'}),/unknown release action/);
for (const patch of [{path:'.github/workflows/gallery-workflows.yml'},{event:'pull_request'},
    {status:'in_progress'},{head_branch:'release/v_2.0'},{conclusion:'cancelled'},{run_attempt:2}]) {
    assert.throws(() => verifyQualification(request,{...run,...patch},jobs,record,record.repository,candidate),/BLOCKED/);
}
for (const patch of [{candidate_sha:'c'.repeat(40)},{ready:false},{ready:'false'},{source_base:'HEAD'},
    {repository:'fork/gallery'},{run_id:'124'},{version:'2.0'},{automated_result:'failure'},{override:true}]) {
    assert.throws(() => verifyQualification(request,run,jobs,{...record,...patch},record.repository,candidate),/BLOCKED/);
}
assert.throws(() => verifyQualification(request,run,jobs,record,record.repository,'d'.repeat(40)),/stale/);
for (const patch of [{origin_commit_sha:'bad'}, {selected_develop_sha:'develop'},
    {previous_stable_tag:'v_1.2.3;bad'}, {previous_stable_sha:'c'.repeat(40)}]) {
    assert.throws(() => verifyQualification(request,run,jobs,{...record,...patch},record.repository,candidate),/provenance/);
}
assert.throws(() => verifyQualification(request,run,jobs.slice(1),record,record.repository,candidate),/missing/);
assert.throws(() => verifyQualification(request,run,[...jobs,jobs[0]],record,record.repository,candidate),/ambiguous/);
for (const conclusion of ['failure','skipped','cancelled',null]) {
    const redJobs = jobs.map(job => job.name === 'Required Chromium fixtures' ? {...job,conclusion} : job);
    assert.throws(() => verifyQualification(request,run,redJobs,record,record.repository,candidate),/red/);
}
// Owner-only authorization: real REST shapes, no network and no GitHub writes.
const ownerRepo='owner/gallery';
const ownerRule=Object.assign(JSON.parse(read('.github/release-ruleset.example.json')),
    {id:24808772,source:ownerRepo,current_user_can_bypass:'never'});
const ownerEnv={protection_rules:[{type:'required_reviewers',prevent_self_review:false,
    reviewers:[{type:'User',reviewer:{login:'owner'}}]}],can_admins_bypass:false,
    deployment_branch_policy:{custom_branch_policies:true,protected_branches:false}};
const ownerPolicies={total_count:1,branch_policies:[{id:98,name:'main'}]};
const server={ruleset:structuredClone(ownerRule),environment:structuredClone(ownerEnv),
    policies:structuredClone(ownerPolicies),permission:'admin'};
const ownerCall=path=>{
    if (path.endsWith('/collaborators/owner/permission')) return {permission:server.permission};
    if (path.endsWith('/environments/release-promotion')) return server.environment;
    if (path.endsWith('/environments/release-promotion/deployment-branch-policies?per_page=100'))
        return server.policies;
    if (path.endsWith('/rulesets')) return [{id:24808772,name:server.ruleset.name,
        enforcement:server.ruleset.enforcement}];
    if (path.endsWith('/rulesets/24808772')) return server.ruleset;
    if (path.endsWith('/rules/branches/main')) return server.ruleset.rules;
    assert.fail('Unexpected owner-only fixture GET '+path);
};
const ownerContext={GITHUB_EVENT_NAME:'workflow_dispatch',GITHUB_REF:'refs/heads/main',GITHUB_ACTOR:'owner'};
assert.equal(requireOwnerDispatch(ownerRepo,ownerCall,ownerContext),'owner');
requireOwnerEnvironment(ownerCall,ownerRepo,'release-promotion');
requireOwnerRuleset(ownerCall,ownerRepo,'main',ownerRule.name,'Release qualification');
assert.throws(()=>requireOwnerDispatch(ownerRepo,ownerCall,{...ownerContext,GITHUB_ACTOR:'intruder'}),/BLOCKED/);
assert.throws(()=>requireOwnerDispatch(ownerRepo,ownerCall,{...ownerContext,GITHUB_REF:'refs/heads/develop'}),/BLOCKED/);
server.permission='read';
assert.throws(()=>requireOwnerDispatch(ownerRepo,ownerCall,ownerContext),/BLOCKED/);
server.permission='admin';
server.environment.can_admins_bypass=true;
assert.throws(()=>requireOwnerEnvironment(ownerCall,ownerRepo,'release-promotion'),/BLOCKED/);
server.environment.can_admins_bypass=false;
server.environment.protection_rules[0].prevent_self_review=true;
assert.throws(()=>requireOwnerEnvironment(ownerCall,ownerRepo,'release-promotion'),/BLOCKED/);
server.environment.protection_rules[0].prevent_self_review=false;
server.environment.protection_rules[0].reviewers[0].reviewer.login='other';
assert.throws(()=>requireOwnerEnvironment(ownerCall,ownerRepo,'release-promotion'),/BLOCKED/);
server.environment=structuredClone(ownerEnv);
server.policies.branch_policies[0].name='develop';
assert.throws(()=>requireOwnerEnvironment(ownerCall,ownerRepo,'release-promotion'),/BLOCKED/);
server.policies=structuredClone(ownerPolicies);
server.policies.branch_policies[0].type='tag';
assert.throws(()=>requireOwnerEnvironment(ownerCall,ownerRepo,'release-promotion'),/BLOCKED/);
server.policies=structuredClone(ownerPolicies);
for (const mutate of [
    state=>{state.enforcement='disabled';},
    state=>{state.bypass_actors=[{actor_id:1,actor_type:'RepositoryRole',bypass_mode:'always'}];},
    state=>{state.rules.find(rule=>rule.type==='pull_request').parameters.require_last_push_approval=true;},
    state=>{state.rules.find(rule=>rule.type==='required_status_checks').parameters.required_status_checks=[];},
    state=>{state.rules.push({type:'required_linear_history'});},
    state=>{state.rules.push(structuredClone(state.rules.find(rule=>rule.type==='required_status_checks')));},
    state=>{state.conditions.ref_name.include=['refs/heads/main','refs/heads/develop'];},
]) {
    server.ruleset=structuredClone(ownerRule);
    mutate(server.ruleset);
    assert.throws(()=>requireOwnerRuleset(ownerCall,ownerRepo,'main',ownerRule.name,'Release qualification'),
        /BLOCKED/);
}
server.ruleset=structuredClone(ownerRule);
delete server.ruleset.bypass_actors;
assert.throws(()=>requireOwnerRuleset(ownerCall,ownerRepo,'main',ownerRule.name,'Release qualification'),
    /BLOCKED/,'GitHub REST bypass-actor redaction must fail closed.');
server.ruleset=structuredClone(ownerRule);
const botPr={number:7,user:{login:'github-actions[bot]'},head:{sha:candidate,
    repo:{full_name:ownerRepo}},base:{ref:'main'},created_at:'2026-10-07T15:00:00Z',
merged_at:'2026-10-08T00:00:00Z'};
requireBotPullRequest(botPr,ownerRepo,candidate,'main');
assert.throws(()=>requireBotPullRequest({...botPr,user:{login:'owner'}},ownerRepo,candidate,'main'),/BLOCKED/);
assert.throws(()=>requireBotPullRequest(botPr,ownerRepo,'c'.repeat(40),'main'),/BLOCKED/);
const ownerReview={id:17,user:{login:'owner'},state:'APPROVED',commit_id:candidate,
    submitted_at:'2026-10-07T18:00:00Z'};
assert.equal(effectiveOwnerReview([ownerReview],botPr,'owner',candidate)?.id,17);
assert.equal(effectiveOwnerReview([{...ownerReview,commit_id:'b'.repeat(40)}],botPr,'owner',candidate),null);
assert.equal(effectiveOwnerReview([ownerReview,{...ownerReview,id:18,state:'DISMISSED',
    submitted_at:'2026-10-07T19:00:00Z'}],botPr,'owner',candidate),null);
assert.equal(effectiveOwnerReview([{...ownerReview,user:{login:'github-actions[bot]'}}],botPr,'owner',candidate),null);
assert.equal(effectiveOwnerReview([ownerReview],{...botPr,user:{login:'owner'}},'owner',candidate),null);
assert.equal(effectiveOwnerReview([ownerReview],{...botPr,merged_at:null},'owner',candidate),null);


const identity='release:123:1:'+candidate;
const boundChecks=['Release qualification','Complete required CI matrix'].map(name=>({id:1,name,head_sha:candidate,
    status:'completed',conclusion:'success',app:{id:15368},external_id:identity}));
const unrelated={...boundChecks[0],external_id:'candidate:500:1:'+candidate};
const checkCall=()=>({total_count:3,check_runs:[...boundChecks,unrelated]});
requireQualificationChecks(checkCall,record.repository,request,'1');
for (const patch of [{app:{id:1}},{head_sha:'b'.repeat(40)},{status:'in_progress'},{conclusion:'failure'}]) {
    const values=[{...boundChecks[0],...patch},boundChecks[1]];
    assert.throws(()=>requireQualificationChecks(()=>({total_count:2,check_runs:values}),record.repository,request,'1'),/BLOCKED/);
}
assert.throws(()=>requireQualificationChecks(()=>({total_count:3,check_runs:[...boundChecks,boundChecks[0]]}),record.repository,request,'1'),/BLOCKED/);
assert.throws(()=>requireQualificationChecks(()=>({total_count:100,check_runs:boundChecks}),record.repository,request,'1'),/incomplete/);
// Real GitHub Actions inventory includes both its runner aggregate and programmatic checks.
const coverageJobs=jobs.map(job=>job.name==='Complete required CI matrix'?{...job,name:'Full CI / '+job.name}:job);
const synthetic=boundChecks.map((check,index)=>({...check,id:900+index,html_url:'https://example.test/check'}));
const rawJobs=[...coverageJobs,...synthetic];
const readJobs=()=>JSON.stringify([{total_count:rawJobs.length,jobs:rawJobs}]);
const readCheck=path=>synthetic.find(check=>path.endsWith('/'+check.id));
assert.deepEqual(fetchJobs(record.repository,'123',1,candidate,readJobs,readCheck),coverageJobs);
assert.deepEqual(verifyQualification(request,run,fetchJobs(record.repository,'123',1,candidate,readJobs,readCheck),record,record.repository,candidate),[]);
const foreign=path=>({...readCheck(path),external_id:'candidate:500:1:'+candidate});
assert.throws(()=>verifyQualification(request,run,fetchJobs(record.repository,'123',1,candidate,readJobs,foreign),record,record.repository,candidate),/ambiguous/);
assert.throws(()=>fetchJobs(record.repository,'123',1,candidate,()=>JSON.stringify([{total_count:rawJobs.length+1,jobs:rawJobs}]),readCheck),/incomplete/);
assert.throws(()=>fetchJobs(record.repository,'123',1,candidate,readJobs,path=>({...readCheck(path),id:1})),/identity/);
assert.throws(()=>verifyQualification({...request,override:true,reason:'outage',acceptedFailures:['Required Chromium fixtures']},run,jobs,record,record.repository,candidate),/overrides/);
const release={id:1,tag_name:'v_1.2',draft:false,prerelease:false,html_url:'https://example.test',assets:[]};
assert.equal(fetchReleases('owner/gallery',()=>JSON.stringify([[release],[{...release,id:2}]])).length,2);
assert.throws(()=>fetchReleases('owner/gallery',()=>JSON.stringify([{releases:[]}])) ,/malformed/);
const qualification=read('.github/workflows/release-qualification.yml');
const completion=read('.github/workflows/release-promotion.yml');
assert.match(qualification,/Initialize or reuse immutable origin after release push/);
assert.doesNotMatch(qualification,/workflow_dispatch:|initialization_run:/);
assert.match(completion,/pull_request_review:/);
assert.match(completion,/types: \[submitted\]/);
assert.match(completion,/Bind successful server run and retained Q record before checkout/);
assert.match(completion,/record\.candidate_sha!==e\.CANDIDATE_SHA/);
assert.doesNotMatch(completion,/workflow_dispatch:|environment:|gh release create|gh release edit|merge_method:'squash'/);
assert.match(qualification,/manual-release-assets/);
assert.match(read('.github/scripts/release-completion.mjs'),/merge_method:'merge'/);
assert.doesNotMatch(read('.github/scripts/release-completion.mjs'),/release','create|release','edit|force-with-lease/);
process.stdout.write('PASS exact qualification identities, complete coverage, no overrides, active policy and manual publication contracts\n');
