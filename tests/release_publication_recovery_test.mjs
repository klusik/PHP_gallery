/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/release_publication_recovery_test.mjs
 * Module Type: Regression Test
 * Purpose: Exercise the pinned v_0.126 publisher with inert GitHub and Git adapters.
 * Responsibilities:
 *   - Reject foreign dispatches, server protection gaps and immutable identity drift
 *   - Revalidate every write and preserve interrupted-draft and identical-retry behavior
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFileSync,writeFileSync,mkdtempSync,rmSync,readdirSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join,basename} from 'node:path';
import {createHash} from 'node:crypto';
import {publish,verifyRecoveryPublication,fetchReleases} from '../.github/scripts/release-promotion.mjs';
import {PUBLICATION_RECOVERY as pinned,requireOwnerDispatch,requireOwnerEnvironment,requireOwnerRuleset,canonicalPolicy} from '../.github/scripts/release-owner-authorization.mjs';

import {publicationReadiness,describeRuleset,readinessApi,readinessCommand} from '../.github/scripts/release-publication-readiness.mjs';

const read=path=>readFileSync(new URL('../'+path,import.meta.url),'utf8');
const legacy=JSON.parse(read('.github/release-legacy/v_0.125.json'));
const origin=JSON.parse(read('.github/release-origins/v_0.126.json'));
const tooling='1'.repeat(40);
const request={runId:pinned.runId,candidate:pinned.candidate,branch:pinned.releaseBranch,mode:'publish',
    override:false,reason:'',acceptedFailures:[],manualReview:'Owner-reviewed publication recovery acceptance'};
const record={repository:pinned.repository,branch:pinned.releaseBranch,candidate_sha:pinned.candidate,
    version:pinned.version,run_id:pinned.runId,run_attempt:pinned.attempt,ready:true,
    origin_commit_sha:'af3b8bfb03d5ddf5eea5bd5c8d4a237df0893460',origin_blob_sha:'a5a7ce0fa0b4889e1767b6fdf5693fda12f7f32d',
    selected_develop_sha:pinned.develop,initial_main_sha:pinned.predecessor,previous_stable_tag:legacy.tag,
    previous_stable_sha:legacy.published_commit_sha,previous_tag_object_sha:legacy.tag_object_sha,
    previous_tag_object_type:'tag',previous_release_candidate_sha:legacy.release_candidate_sha,
    previous_tree_sha:legacy.tree_sha,predecessor_kind:'legacy-v_0.125',source_base:legacy.release_candidate_sha,base_tag:legacy.tag};
const requiredJobs=['Prepare release candidate','Read-only generated-state and source preflight',
    'Positive production package (ubuntu-24.04)','Positive production package (windows-2025)','Positive production package (macos-latest)',
    'PHP 8.3 workflows (mysql:8.4, Unicode)','PHP 8.3 workflows (mariadb:10.11, Unicode)','PHP 8.5 workflows (mariadb:11.4, Unicode)',
    'PHP 8.1 source (unicode)','PHP 8.5 source (ascii)','Required Chromium fixtures','Authoritative release audit','Complete required CI matrix','Release qualification gate'];
const directory=mkdtempSync(join(tmpdir(),'gallery-publication-recovery-'));
const saved={RELEASE_ASSETS:process.env.RELEASE_ASSETS,GITHUB_STEP_SUMMARY:process.env.GITHUB_STEP_SUMMARY};
process.env.RELEASE_ASSETS=directory;
delete process.env.GITHUB_STEP_SUMMARY;
const digest=data=>createHash('sha256').update(data).digest('hex');

// Each mutable fixture is independent; every API/command write is captured here.
const fixture=()=>{
    const state={context:{GITHUB_EVENT_NAME:'workflow_dispatch',GITHUB_REF:pinned.ref,GITHUB_SHA:tooling,
        GITHUB_ACTOR:pinned.owner,GITHUB_TRIGGERING_ACTOR:pinned.owner},
        request:structuredClone(request),record:structuredClone(record),permission:'admin',
        tokenPermissions:{push:false,pull:false,admin:false,maintain:false,triage:false},attestation:null,effectiveRules:null,apiDenied:false,
        refs:{main:pinned.main,develop:pinned.develop,[pinned.releaseBranch]:pinned.candidate,[pinned.branch]:tooling},
        environment:{can_admins_bypass:false,protection_rules:[{type:'required_reviewers',prevent_self_review:false,
            reviewers:[{type:'User',reviewer:{login:pinned.owner}}]}],deployment_branch_policy:{custom_branch_policies:true,protected_branches:false}},
        policies:{total_count:1,branch_policies:[{name:pinned.branch,type:'branch'}]},
        ruleset:{...JSON.parse(read('.github/release-ruleset.example.json')),id:24808772,source:pinned.repository,source_type:'Repository',node_id:'RRS_real_fixture',
            created_at:'2026-10-09T18:47:13Z',updated_at:'2026-10-09T23:02:04Z',current_user_can_bypass:'never'},
        run:{path:'.github/workflows/release-qualification.yml',event:'push',head_branch:pinned.releaseBranch,
            run_attempt:1,status:'completed',conclusion:'success'},
        jobs:requiredJobs.map(name=>({name,status:'completed',conclusion:'success',html_url:'fixture'})),
        checks:['Release qualification','Complete required CI matrix'].map(name=>({name,app:{id:15368},head_sha:pinned.candidate,
            status:'completed',conclusion:'success',external_id:'release:'+pinned.runId+':1:'+pinned.candidate})),
        pr:{number:179,node_id:'PR_kwDOSNlPsc8AAAABHoJi4w',state:'closed',merged:true,user:{login:'github-actions[bot]'},
            head:{ref:pinned.releaseBranch,sha:pinned.candidate,repo:{full_name:pinned.repository}},
            base:{ref:'main',sha:pinned.predecessor,repo:{full_name:pinned.repository}},
            merged_by:{login:pinned.owner},auto_merge:null,merge_commit_sha:pinned.main,
            created_at:'2026-10-09T22:00:00Z',merged_at:'2026-10-09T23:22:03Z',html_url:'https://github.com/klusik/PHP_gallery/pull/179'},
        reviews:[{id:5476274538,user:{login:pinned.owner},state:'APPROVED',commit_id:pinned.candidate,submitted_at:'2026-10-09T23:20:28Z'}],
        parents:[{sha:pinned.predecessor},{sha:pinned.candidate}],tree:pinned.tree,
        committedOrigin:structuredClone(origin),blob:record.origin_blob_sha,tag:null,release:null,history:[],
        interrupt:false,driftAfterTag:false,driftBeforePublic:false,assetDriftAfterTag:false,
        detailPatch:null,detailUnavailable:false,detailReads:0,writes:[]};
    state.checks.unshift({...state.checks[1],external_id:'independent-pr-check'});
    const api=(path,method='GET',payload=null)=>{
        if (method!=='GET') state.writes.push({path,method,payload});
        if (state.apiDenied) throw new Error('BLOCKED_API_PERMISSION: HTTP 403');
        if (path==='repos/'+pinned.repository) return {permissions:state.tokenPermissions};
        if (path.endsWith('/issues/comments/12345')) return state.attestation;
        if (path.includes('/collaborators/')) return {permission:state.permission};
        if (path.endsWith('/deployment-branch-policies?per_page=100')) return state.policies;
        if (path.endsWith('/environments/'+pinned.environment)) return state.environment;
        if (path.endsWith('/rulesets?per_page=100')) return [{id:24808772,name:state.ruleset.name,enforcement:state.ruleset.enforcement}];
        if (path.endsWith('/rulesets/24808772')) return state.ruleset;
        if (path.endsWith('/rules/branches/main')) return state.effectiveRules ?? state.ruleset.rules;
        if (path.endsWith('/actions/runs/'+pinned.runId)) return state.run;
        if (path.includes('/attempts/1/jobs?')) return {total_count:state.jobs.length,jobs:state.jobs};
        if (path.includes('/check-runs?')) return {total_count:state.checks.length,check_runs:state.checks};
        if (path.includes('/pulls?')) {
            const listed=structuredClone(state.pr);
            delete listed.merged_by;
            delete listed.merged;
            return [listed];
        }
        if (path.endsWith('/pulls/'+state.pr.number)) {
            state.detailReads++;
            if (state.detailUnavailable) throw new Error('simulated GitHub PR detail API failure');
            return {...structuredClone(state.pr),...state.detailPatch};
        }
        if (path.includes('/reviews?')) return state.reviews;
        if (path.endsWith('/commits/main')) return {sha:state.refs.main,commit:{tree:{sha:state.tree}},parents:state.parents};
        if (path.endsWith('/commits/'+pinned.candidate)) return {sha:pinned.candidate,commit:{tree:{sha:pinned.tree}}};
        if (path.endsWith('/git/ref/tags/'+legacy.tag)) return {object:{type:'tag',sha:legacy.tag_object_sha}};
        if (path.endsWith('/git/tags/'+legacy.tag_object_sha)) return {sha:legacy.tag_object_sha,object:{type:'commit',sha:legacy.published_commit_sha}};
        if (path.endsWith('/commits/'+legacy.published_commit_sha)) return {sha:legacy.published_commit_sha,
            commit:{tree:{sha:legacy.tree_sha}},parents:[{sha:legacy.first_parent_sha},{sha:legacy.release_candidate_sha}]};
        if (path.endsWith('/commits/'+legacy.release_candidate_sha)) return {sha:legacy.release_candidate_sha,commit:{tree:{sha:legacy.tree_sha}}};
        if (path.endsWith('/releases/tags/'+legacy.tag)) return {tag_name:legacy.tag,draft:false,prerelease:false,assets:[]};
        if (path.includes('/git/ref/heads/')) return {object:{sha:state.refs[path.split('/git/ref/heads/')[1]]}};
        if (path.includes('/compare/')) return {behind_by:0};
        if (path.includes('/git/matching-refs/')) return state.tag ? [{ref:'refs/tags/'+pinned.tag,object:{type:'commit',sha:state.tag}}] : [];
        if (path.endsWith('/git/refs') && method==='POST') {
            assert.deepEqual(payload,{ref:'refs/tags/'+pinned.tag,sha:pinned.main});
            state.tag=payload.sha;
            if (state.driftAfterTag) state.refs.develop='0'.repeat(40);
            if (state.rulesetDriftAfterTag) state.ruleset.updated_at='2026-10-10T00:00:00Z';
            if (state.assetDriftAfterTag) writeFileSync(join(directory,'production.zip'),'changed upload bytes');
            return {};
        }
        if (path.endsWith('/git/ref/tags/'+pinned.tag)) return {object:{sha:state.tag}};
        if (path.endsWith('/releases?per_page=100')) return state.release ? [structuredClone(state.release)] : [];
        if (path.endsWith('/releases/tags/'+pinned.tag)) return structuredClone(state.release);
        if (path.endsWith('/releases/9') && method==='PATCH') {state.release.draft=payload.draft;return structuredClone(state.release);}
        assert.fail('Unexpected recovery API '+method+' '+path);
    };
    const command=(executable,args)=>{
        if (executable==='git') {
            if (args[0]==='show') return JSON.stringify(state.committedOrigin);
            if (args[0]==='log') return record.origin_commit_sha;
            if (args[0]==='rev-list') return record.origin_commit_sha+' '+pinned.develop;
            if (args[0]==='rev-parse') return state.blob;
            if (args[0]==='diff') return '';
            assert.fail('Unexpected recovery Git '+args.join(' '));
        }
        assert.equal(executable,'gh');
        if (args[0]==='api') {
            assert.deepEqual(args,['api','repos/'+pinned.repository+'/releases?per_page=100','--paginate','--slurp']);
            return JSON.stringify(state.history.length ? [state.history,state.release ? [state.release] : []] : [state.release ? [state.release] : []]);
        }
        assert.equal(args[0],'release');
        state.writes.push({command:args});
        if (args[1]==='create') {
            assert.ok(args.includes('--draft') && args.includes('--verify-tag'));
            state.release={id:9,tag_name:pinned.tag,draft:true,prerelease:false,assets:[],html_url:'fixture'};
        } else if (args[1]==='upload') {
            if (state.interrupt && state.release.assets.length===2) throw new Error('interrupted upload');
            const name=basename(args[3]);
            const bytes=readFileSync(args[3]);
            state.release.assets.push({name,size:bytes.length,digest:'sha256:'+digest(bytes)});
            if (state.driftBeforePublic && state.release.assets.length===readdirSync(directory).length) state.refs.develop='0'.repeat(40);
        } else assert.fail('Unexpected recovery release command');
        return '';
    };
    return {state,api,command,context:state.context,record:state.record};
};
const prepareAssets=()=>{
    for (const name of readdirSync(directory)) rmSync(join(directory,name));
    const names=['production.zip','core-manifest.json','production-files.json','release-metadata.json','release-notes.md','qualification-evidence.zip'];
    const hashes={};
    for (const name of names.sort()) {
        writeFileSync(join(directory,name),'public fixture '+name);
        hashes[name]=digest(readFileSync(join(directory,name)));
    }
    writeFileSync(join(directory,'SHA256SUMS'),Object.entries(hashes).map(([name,hash])=>hash+'  '+name).join('\n')+'\n');
    hashes.SHA256SUMS=digest(readFileSync(join(directory,'SHA256SUMS')));
    writeFileSync(join(directory,'final-integrity.json'),JSON.stringify({candidate_sha:pinned.candidate,source_base:record.source_base,result:'PASS',hashes}));
};
// Approval is inert fixture evidence, never an owner attestation created on GitHub.
const attest=f=>{
    const policy=structuredClone(f.state.ruleset);
    const approval={schema_version:1,decision:'APPROVE_RULESET_ATTESTATION_SECURITY_CONTRACT_V1',
        repository:pinned.repository,ruleset_id:24808772,qualification_sha:pinned.candidate,main_sha:pinned.main,
        recovery_ref:pinned.ref,tooling_sha:tooling,qualification_run:pinned.runId,qualification_attempt:pinned.attempt,
        environment:pinned.environment,owner:pinned.owner,policy,
        policy_sha256:digest(canonicalPolicy(policy))};
    f.state.attestation={id:12345,user:{login:pinned.owner,type:'User'},author_association:'OWNER',
        issue_url:'https://api.github.com/repos/'+pinned.repository+'/issues/133',
        created_at:'2026-10-10T00:00:00Z',updated_at:'2026-10-10T00:00:00Z',body:JSON.stringify(approval)};
    f.state.context.RULESET_ATTESTATION_COMMENT='12345';
    delete f.state.ruleset.bypass_actors;
    return approval;
};
try {
    assert.throws(()=>readinessApi('repos/'+pinned.repository+'/git/refs','POST',{}),/BLOCKED_READINESS_WRITE/);
    assert.throws(()=>readinessCommand('gh',['release','create',pinned.tag]),/BLOCKED_READINESS_WRITE/);
    assert.throws(()=>readinessCommand('git',['diff','--output=unexpected']),/BLOCKED_READINESS_WRITE/);
    const absent=fixture();delete absent.state.ruleset.bypass_actors;
    assert.deepEqual(describeRuleset(absent.state.ruleset).original_failing_predicates,
        ['!Array.isArray(installed.bypass_actors)']);
    assert.equal(describeRuleset(absent.state.ruleset).fields.bypass_actors.type,'absent');
    assert.throws(()=>verifyRecoveryPublication(request,pinned.repository,record,absent),/BLOCKED_RULESET_ATTESTATION_REQUIRED/);
    for (const [bypass,code] of [[null,'BLOCKED_RULESET_BYPASS_TYPE'],[{},'BLOCKED_RULESET_BYPASS_TYPE'],[[{actor_id:1}],'BLOCKED_RULESET_BYPASS_ACTORS']]) {
        const f=fixture();f.state.ruleset.bypass_actors=bypass;
        assert.throws(()=>verifyRecoveryPublication(request,pinned.repository,record,f),new RegExp(code));
    }
    for (const mutation of [
        f=>{f.state.ruleset.updated_at='2026-10-10T01:00:00Z';},
        f=>{delete f.state.ruleset.updated_at;},f=>{f.state.ruleset.node_id='changed';},
        f=>{f.state.ruleset.unexpected_policy=true;},f=>{f.state.ruleset.current_user_can_bypass='always';},
        f=>{f.state.attestation.user.login='intruder';},f=>{f.state.attestation.user.type='Bot';},
        f=>{f.state.attestation.updated_at='2026-10-10T01:00:00Z';},f=>{f.state.attestation.author_association='MEMBER';},
        f=>{f.state.context.GITHUB_SHA=pinned.main;},f=>{f.state.attestation.issue_url='https://api.github.com/repos/other/repo/issues/133';},
        f=>{const a=JSON.parse(f.state.attestation.body);a.tooling_sha=pinned.main;f.state.attestation.body=JSON.stringify(a);},
        f=>{const a=JSON.parse(f.state.attestation.body);a.decision='APPROVED';f.state.attestation.body=JSON.stringify(a);},
        f=>{const a=JSON.parse(f.state.attestation.body);a.policy.bypass_actors=[{actor_id:1}];a.policy_sha256=digest(canonicalPolicy(a.policy));f.state.attestation.body=JSON.stringify(a);},
        f=>{const a=JSON.parse(f.state.attestation.body);a.policy_sha256='0'.repeat(64);f.state.attestation.body=JSON.stringify(a);},
        f=>{f.state.ruleset.bypass_actors=[{actor_id:1}];},
        f=>{f.state.effectiveRules=structuredClone(f.state.ruleset.rules);f.state.effectiveRules[0].ruleset_id=99;},
        f=>{f.state.effectiveRules=structuredClone(f.state.ruleset.rules);f.state.effectiveRules[2].parameters.require_code_owner_review=true;},
        f=>{f.state.tokenPermissions.maintain=true;},f=>{f.state.tokenPermissions.admin=true;},f=>{delete f.state.tokenPermissions.push;},f=>{f.state.apiDenied=true;},
    ]) {
        prepareAssets();const f=fixture();attest(f);mutation(f);
        await assert.rejects(publish(f.state.request,pinned.repository,f.state.record,[],f),/BLOCKED/);
        assert.equal(f.state.writes.length,0);
    }
    prepareAssets();const attested=fixture();attest(attested);
    attested.state.ruleset.updated_at='2026-10-10T01:02:04+02:00';
    attested.state.ruleset.created_at='2026-10-09T20:47:13+02:00';
    verifyRecoveryPublication(request,pinned.repository,record,attested);
    const ready=publicationReadiness(request,record,attested);
    assert.equal(ready.result,'PASS');assert.equal(attested.state.writes.length,0);
    assert.ok(!readdirSync(directory).includes('release-evidence.json'),'Readiness never creates a release asset.');
    attested.state.context.GITHUB_EVENT_NAME='push';
    assert.equal(publicationReadiness(request,record,attested).result,'PASS','A real owner push may collect read-only evidence.');
    await assert.rejects(publish(request,pinned.repository,record,[],attested),/BLOCKED/,'A push never authorizes publication.');
    prepareAssets();const restricted=fixture();delete restricted.state.ruleset.bypass_actors;
    const blocked=publicationReadiness(request,record,restricted);
    assert.equal(blocked.result,'BLOCKED');assert.equal(blocked.checks.write_controls.code,'BLOCKED_RULESET_ATTESTATION_REQUIRED');
    assert.equal(blocked.checks.prepared_assets.result,'PASS');assert.equal(blocked.checks.release_inventory.result,'PASS');
    assert.equal(restricted.state.writes.length,0,'Independent readiness phases remain read-only after a policy blocker.');
    prepareAssets();const policyDrift=fixture();attest(policyDrift);policyDrift.state.rulesetDriftAfterTag=true;
    await assert.rejects(publish(request,pinned.repository,record,[],policyDrift),/BLOCKED_RULESET_ATTESTATION_DRIFT/);
    assert.equal(policyDrift.state.writes.length,1,'Ruleset drift after tag blocks every later write.');
    const good=fixture();
    verifyRecoveryPublication(request,pinned.repository,record,good);
    assert.equal(good.state.detailReads,1,'Recovery reads the complete PR detail after discovery.');
    assert.throws(()=>fetchReleases(pinned.repository,()=>'{"partial":true}'),/BLOCKED/);
    assert.throws(()=>fetchReleases(pinned.repository,()=>'[]'),/BLOCKED/);
    assert.throws(()=>fetchReleases(pinned.repository,()=>'[{}]'),/BLOCKED/);
    assert.throws(()=>requireOwnerDispatch(pinned.repository,good.api,good.context),/BLOCKED/,'The default API stays main-only.');
    assert.throws(()=>requireOwnerEnvironment(good.api,pinned.repository,pinned.environment),/BLOCKED/);
    for (const mutate of [
        s=>{s.context.GITHUB_ACTOR='intruder';},s=>{s.context.GITHUB_TRIGGERING_ACTOR='intruder';},
        s=>{s.context.GITHUB_REF='refs/heads/feature/other';},s=>{s.context.GITHUB_EVENT_NAME='push';},
        s=>{s.request.mode='promote';},s=>{s.request.mode='bootstrap';},s=>{s.request.mode='plan';},
        s=>{s.request.candidate=tooling;},s=>{s.request.runId='123';},s=>{s.request.branch='release/v_0.127';},
        s=>{s.request.manualReview='';},s=>{s.request.override=true;},s=>{s.record.run_attempt='2';},
        s=>{s.record.initial_main_sha=tooling;},s=>{s.refs.main=tooling;},s=>{s.refs.develop=tooling;},
        s=>{s.refs[pinned.releaseBranch]=tooling;},s=>{s.refs[pinned.branch]=pinned.main;},
        s=>{s.pr.number=180;},s=>{s.pr.merged_by.login='github-actions[bot]';},s=>{s.pr.auto_merge={};},
        s=>{delete s.pr.merged_by;},s=>{delete s.pr.node_id;},s=>{s.detailPatch={number:180};},
        s=>{s.detailPatch={node_id:'foreign-pr'};},s=>{s.detailPatch={merged_at:'2026-10-09T23:23:03Z'};},
        s=>{s.detailPatch={head:{...s.pr.head,ref:'release/v_0.127'}};},s=>{s.detailUnavailable=true;},
        s=>{s.reviews[0].id=1;},s=>{s.reviews[0].state='DISMISSED';},s=>{s.reviews[0].commit_id=tooling;},
        s=>{s.parents.reverse();},s=>{s.tree=tooling;},s=>{s.run.run_attempt=2;},
        s=>{s.run.event='pull_request';},s=>{s.run.conclusion='failure';},s=>{s.jobs.pop();},
        s=>{s.jobs.push({...s.jobs[0]});},s=>{s.jobs[3].conclusion='skipped';},s=>{s.jobs[3].conclusion='failure';},
        s=>{s.checks[1].conclusion='failure';},s=>{s.checks.push({...s.checks[1]});},s=>{s.checks[1].app={id:1};},
        s=>{s.permission='read';},s=>{s.environment.can_admins_bypass=true;},
        s=>{s.environment.protection_rules[0].prevent_self_review=true;},
        s=>{s.environment.protection_rules[0].reviewers[0].reviewer.login='other';},
        s=>{s.policies.branch_policies[0].name='main';},s=>{s.policies.branch_policies[0].name='hotfix/*';},
        s=>{s.policies.branch_policies[0].type='tag';},s=>{delete s.ruleset.bypass_actors;},
        s=>{s.ruleset.enforcement='disabled';},s=>{s.tag=tooling;},
        s=>{s.release={id:9,tag_name:pinned.tag,draft:true,prerelease:true,assets:[]};},
        s=>{s.release={id:9,tag_name:pinned.tag,draft:true,prerelease:false,
            assets:[{name:'production.zip',size:1,digest:'sha256:incorrect'}]};},
        s=>{s.committedOrigin.selected_develop_sha=tooling;},s=>{s.blob=tooling;},
    ]) {
        prepareAssets();
        const f=fixture();mutate(f.state);
        await assert.rejects(publish(f.state.request,pinned.repository,f.state.record,[],f),/BLOCKED/);
        assert.equal(f.state.writes.length,0,'Invalid recovery must be rejected before any server write.');
    }
    prepareAssets();
    const checksumFixture=fixture();
    writeFileSync(join(directory,'SHA256SUMS'),'invalid checksums');
    await assert.rejects(publish(request,pinned.repository,record,[],checksumFixture),/asset bytes changed|checksums/);
    assert.equal(checksumFixture.state.writes.length,0);
    const checksumIntegrity=JSON.parse(readFileSync(join(directory,'final-integrity.json'),'utf8'));
    checksumIntegrity.hashes.SHA256SUMS=digest(readFileSync(join(directory,'SHA256SUMS')));
    writeFileSync(join(directory,'final-integrity.json'),JSON.stringify(checksumIntegrity));
    await assert.rejects(publish(request,pinned.repository,record,[],checksumFixture),/checksums/);
    assert.equal(checksumFixture.state.writes.length,0);
    for (const drift of ['driftAfterTag','assetDriftAfterTag','driftBeforePublic']) {
        prepareAssets();const f=fixture();f.state[drift]=true;
        await assert.rejects(publish(request,pinned.repository,record,[],f),/branch changed|asset bytes changed/);
        assert.ok(!f.state.writes.some(write=>write.payload?.draft===false),'No early publication after mid-run drift.');
        if (drift!=='driftBeforePublic') assert.equal(f.state.writes.length,1,'Revalidation stops the next write after tag creation.');
    }
    prepareAssets();const retry=fixture();retry.state.interrupt=true;
    retry.state.history=Array.from({length:100},(_,index)=>({id:index+100,tag_name:'v_0.'+index,
        draft:false,prerelease:false,html_url:'fixture',assets:[]}));
    await assert.rejects(publish(request,pinned.repository,record,[],retry),/interrupted upload/);
    assert.equal(retry.state.tag,pinned.main);assert.equal(retry.state.release.draft,true);
    retry.state.interrupt=false;
    await publish(request,pinned.repository,record,[],retry);
    assert.equal(retry.state.release.draft,false);
    const evidence=JSON.parse(readFileSync(join(directory,'release-evidence.json'),'utf8'));
    assert.equal(evidence.publication_recovery.tooling_sha,tooling);
    assert.equal(evidence.promotion_pr.number,179);
    assert.equal(evidence.final_main_sha,pinned.main);
    const writes=retry.state.writes.length;
    assert.equal(fetchReleases(pinned.repository,retry.command).length,101,'A release beyond the first page stays visible.');
    await publish(request,pinned.repository,record,[],retry);
    assert.equal(retry.state.writes.length,writes,'Identical retry writes nothing.');
    retry.state.release.assets[0].digest='sha256:conflicting-second-page-asset';
    await assert.rejects(publish(request,pinned.repository,record,[],retry),/existing release asset/);
    assert.equal(retry.state.writes.length,writes,'Conflicts beyond the first page cannot create or update a tag.');
    const secondPageConflict=fixture();
    secondPageConflict.state.history=structuredClone(retry.state.history);
    secondPageConflict.state.release={...structuredClone(retry.state.release),draft:true};
    await assert.rejects(publish(request,pinned.repository,record,[],secondPageConflict),/existing release asset/);
    assert.equal(secondPageConflict.state.writes.length,0,'A conflicting second-page draft cannot create the missing tag.');
    assert.ok(!retry.state.writes.some(write=>write.path?.includes('/heads/') || write.path?.includes('/pulls')));
    const workflow=read('.github/workflows/release-promotion.yml');
    const recoveryJob=workflow.split('  recover-publication:')[1].split('  bootstrap:')[0];
    assert.match(recoveryJob,/needs: \[inspect, package, installer, publication-readiness\]/);
    assert.match(recoveryJob,/needs.publication-readiness.result == 'success'/);
    const readinessJob=workflow.split('  publication-readiness:')[1].split('  apply:')[0];
    assert.doesNotMatch(readinessJob,/environment:/);
    assert.match(readinessJob,/release-publication-readiness.mjs/);
    const diagnostic=read('.github/workflows/publication-readiness.yml');
    assert.doesNotMatch(diagnostic,/environment:|release-promotion.mjs|release create|git push/);
    assert.match(recoveryJob,/environment: release-publication-recovery-v_0.126/);
    assert.match(recoveryJob,/pull-requests: read/);
    assert.doesNotMatch(recoveryJob,/pull-requests: write|checks: write|actions: write|secrets: inherit/);
    assert.match(recoveryJob,/run: node .github\/scripts\/release-promotion.mjs/);
    assert.match(workflow,/github.ref == 'refs\/heads\/main' && inputs.mode != 'plan'/);
    process.stdout.write('PASS pinned publication recovery, owner controls, immutable evidence, per-write drift and draft retry\n');
} finally {
    for (const [name,value] of Object.entries(saved)) {
        if (value===undefined) delete process.env[name];else process.env[name]=value;
    }
    rmSync(directory,{recursive:true,force:true});
}
