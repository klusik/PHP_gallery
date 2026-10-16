/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/feature_qualification_test.mjs
 * Module Type: Feature Qualification Regression
 * Purpose: Exercise owner-selected feature FULL, sealed publication recovery and develop-owned release sources.
 * Responsibilities: Preserve real native owner requirements while avoiding main-only and historical archive prerequisites.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {readFileSync} from 'node:fs';
import {loadFeatureExecution,verifyFeatureOwners} from '../.github/scripts/feature-qualification.mjs';
import {bindTrustedBasicReport} from '../.github/scripts/trusted-basic.mjs';
import {loadDevelopSource} from '../.github/scripts/coverage-artifacts.mjs';
import {releaseStableMain,verifyMaintenanceMainBridge} from '../.github/scripts/release-origin.mjs';
import {fullMatrixOwners} from '../.github/scripts/ci-owner-contract.mjs';
import {coverageFixtureZip} from './support/coverage_artifact_fixture.mjs';
import {ciOwnerWorkflowResponse} from './support/ci_owner_fixture.mjs';
import {qualifyCiInstallation} from '../.github/scripts/ci-installation.mjs';

/** Build real in-memory archives and independently mutable provider evidence for a feature execution.
 * @returns {{repository:string,expected:{candidate:string,tree:string,base:string},record:Record<string,unknown>,run:Record<string,unknown>,jobs:Record<string,unknown>[],api:function(string):Record<string,unknown>,download:function(string,number):Buffer}} Inert exact-source execution and provider records; no Git or network writes occur.
 */
function featureFixture() {
    const repository='owner/gallery',candidate='a'.repeat(40),tree='b'.repeat(40),base='c'.repeat(40),authority='d'.repeat(40);
    const run={id:100,run_attempt:1,head_sha:authority,head_branch:'feature/test-changes',
        path:'.github/workflows/develop-integration.yml',event:'workflow_dispatch',status:'completed',conclusion:'failure',
        actor:{login:'owner'},triggering_actor:{login:'owner'}};
    const jobs=fullMatrixOwners().map((name,index)=>({id:1000+index,name:'Develop integration / '+name,
        workflow_name:'Integrate into develop',run_id:100,run_attempt:1,status:'completed',conclusion:'success'}));
    jobs.push({id:1100,name:'Retain integration execution',workflow_name:'Integrate into develop',
        run_id:100,run_attempt:1,status:'completed',conclusion:'success'});
    const report={profile:'basic',status:'PASS',source:{head_sha:candidate,comparison_base:base,worktree_dirty:false},
        tasks:['php-lint','js-lint','source-documentation-changed'].map(id=>({id,status:'PASS'}))};
    const reportBytes=Buffer.from(JSON.stringify(report));
    const basic=bindTrustedBasicReport(report,{authority,source:candidate,tree,base},reportBytes);
    const basicBytes=coverageFixtureZip([['trusted-basic.json',Buffer.from(JSON.stringify(basic))],['trusted-basic.json.report.json',reportBytes]]);
    const basicDigest='sha256:'+createHash('sha256').update(basicBytes).digest('hex');
    const record={schema_version:2,repository,source_branch:'feature/test-changes',source_sha:candidate,
        candidate_sha:candidate,candidate_tree:tree,source_base:base,expected_develop_sha:base,
        workflow_sha:authority,workflow_path:run.path,run_id:'100',run_attempt:'1',native_owners:jobs.slice(0,-1),
        feature_qualification:{schema_version:1,input_tree:tree,basic:{...basic,artifact_id:300,artifact_sha256:basicDigest},
            execution:{run_id:'100',run_attempt:'1',workflow_sha:authority}}};
    const bytes=coverageFixtureZip([['develop-execution.json',Buffer.from(JSON.stringify(record))]]);
    const digest='sha256:'+createHash('sha256').update(bytes).digest('hex');
    const metadata=(id,name,data,hash)=>({id,name,digest:hash,expired:false,size_in_bytes:data.length,workflow_run:{id:100}});
    const executionArtifact=metadata(200,'develop-integration-execution',bytes,digest);
    const api=path=>{
        const workflow=ciOwnerWorkflowResponse(path);if (workflow) return workflow;
        if (path.endsWith('/collaborators/owner/permission')) return {permission:'admin'};
        if (path.endsWith('/actions/runs/100')) return structuredClone(run);
        if (path.endsWith('/actions/runs/100/attempts/1/jobs?per_page=100&page=1')) return {total_count:jobs.length,jobs:structuredClone(jobs)};
        if (path.endsWith('/actions/runs/100/artifacts?per_page=100&page=1')) return {total_count:1,artifacts:[executionArtifact]};
        if (path.endsWith('/actions/artifacts/200')) return executionArtifact;
        if (path.endsWith('/actions/artifacts/300')) return metadata(300,'develop-integration-inherited-basic',basicBytes,basicDigest);
        throw new Error('Unexpected fixture API: '+path);
    };
    return {repository,expected:{candidate,tree,base},record,run,jobs,api,
        download:(_repository,id)=>id===200?bytes:basicBytes};
}

{
    const f=featureFixture();
    const accepted=loadFeatureExecution(f.repository,'100',f.expected,f);
    assert.equal(accepted.record.candidate_sha,f.expected.candidate);
    assert.equal(accepted.record.feature_qualification.execution.workflow_sha,'d'.repeat(40));
    assert.equal(f.run.conclusion,'failure','a failed publication does not erase sealed successful FULL');
    for (const change of [{candidate:'f'.repeat(40)},{tree:'f'.repeat(40)},{base:'f'.repeat(40)}]) {
        assert.throws(()=>loadFeatureExecution(f.repository,'100',{...f.expected,...change},f),/BLOCKED/);
    }
    for (const conclusion of ['failure','cancelled','skipped']) {
        const g=featureFixture();g.jobs[0].conclusion=conclusion;
        assert.throws(()=>loadFeatureExecution(g.repository,'100',g.expected,g),/BLOCKED/,'a bad mandatory owner cannot be inherited');
    }
    for (const change of [
        value=>{value.run.triggering_actor={login:'attacker'};},
        value=>{value.run.event='push';},value=>{value.run.run_attempt=2;},
        value=>{value.run.conclusion='cancelled';},
        value=>{value.jobs.at(-1).conclusion='failure';},
        value=>{value.jobs.push({...value.jobs[0],id:9999});},
    ]) {
        const g=featureFixture();change(g);
        assert.throws(()=>loadFeatureExecution(g.repository,'100',g.expected,g),/BLOCKED/);
    }
    const ownerRows=f.record.native_owners;
    assert.equal(verifyFeatureOwners(f.repository,f.record.feature_qualification.execution,ownerRows,f.api).length,29);
    assert.throws(()=>loadFeatureExecution(f.repository,'100',f.expected,{...f,download:()=>Buffer.from('corrupt archive')}),/BLOCKED/);
}

{
    const candidate='a'.repeat(40),tree='b'.repeat(40),main='c'.repeat(40),stable='d'.repeat(40),old='e'.repeat(40);
    const call=path=>{
        assert.ok(!/artifacts|check-runs|actions\/runs|pulls/.test(path),'release never reopens historical CI qualification');
        if (path.endsWith('/git/ref/heads/develop')) return {object:{sha:candidate}};
        if (path.endsWith('/commits/'+candidate)) return {sha:candidate,commit:{tree:{sha:tree}}};
        if (path.endsWith('/commits/'+main)) return {sha:main,parents:[{sha:stable},{sha:candidate}]};
        if (path.endsWith('/git/ref/tags/v_1.0')) return {object:{type:'commit',sha:stable}};
        if (path.endsWith('/commits/'+stable)) return {sha:stable,commit:{tree:{sha:tree}},parents:[{sha:old},{sha:candidate}]};
        throw new Error('Unexpected release fixture: '+path);
    };
    assert.deepEqual(loadDevelopSource('owner/gallery',candidate,{api:call}),{candidate_sha:candidate,candidate_tree:tree,trust:'protected-develop'});
    assert.equal(releaseStableMain('1.1',main,'owner/gallery',{api:call,stableTags:[{tag:'v_1.0',sha:stable}]}),stable);
    assert.doesNotThrow(()=>verifyMaintenanceMainBridge({version:'1.1',initial_main_sha:main,previous_stable_sha:stable,
        previous_stable_tag:'v_1.0'},'owner/gallery',{api:call}));
    assert.throws(()=>loadDevelopSource('owner/gallery','f'.repeat(40),{api:()=>({})}),/BLOCKED/);
    const workflow=readFileSync(new URL('../.github/workflows/release-qualification.yml',import.meta.url),'utf8');
    assert.ok(!workflow.includes('scripts/audit.php'),'release assembles qualified develop without another audit');
    assert.ok(!workflow.includes('loadIntegrationQualification'),'release does not require expiring FULL archives');
    assert.ok(workflow.includes('loadDevelopSource'));
}
process.stdout.write('PASS owner-selected feature FULL, sealed publication recovery and protected-develop release source\n');

{
    const f=featureFixture(),env={GITHUB_REPOSITORY:f.repository,GITHUB_ACTOR:'owner',GITHUB_EVENT_NAME:'workflow_dispatch',
        GITHUB_REF_NAME:'feature/ci-install',GITHUB_SHA:'d'.repeat(40),GITHUB_RUN_ID:'100',GITHUB_RUN_ATTEMPT:'1',
        CANDIDATE_SHA:f.expected.candidate,SOURCE_BASE:f.expected.base};
    const rules=[{type:'deletion'},{type:'non_fast_forward'},
        {type:'required_status_checks',parameters:{do_not_enforce_on_create:false,strict_required_status_checks_policy:false,
            required_status_checks:[{context:'Release qualification',integration_id:15368}]}},
        {type:'pull_request',parameters:{required_approving_review_count:1,dismiss_stale_reviews_on_push:true,
            require_code_owner_review:false,require_last_push_approval:false,required_review_thread_resolution:true,allowed_merge_methods:['merge']}}];
    const name='Reviewed release promotion to main';
    const jobs=[...fullMatrixOwners(),'Authoritative release audit'].map((label,index)=>({id:5000+index,
        name:'Full CI for the published prepared SHA / '+label,run_id:100,run_attempt:1,status:'completed',conclusion:'success'}));
    const call=path=>{
        if (path.endsWith('/collaborators/owner/permission')) return {permission:'admin'};
        if (path.endsWith('/actions/runs/100')) return {...f.run,path:'.github/workflows/candidate-preparation.yml',
            head_branch:env.GITHUB_REF_NAME,status:'in_progress',conclusion:null};
        if (path.endsWith('/git/ref/heads/main')) return {object:{sha:env.SOURCE_BASE}};
        if (path.endsWith('/git/ref/heads/'+env.GITHUB_REF_NAME)) return {object:{sha:env.CANDIDATE_SHA}};
        if (path.includes('/compare/')) return {status:'ahead',behind_by:0,ahead_by:1,merge_base_commit:{sha:env.SOURCE_BASE},
            files:[{filename:'.github/scripts/ci-installation.mjs',status:'added'},{filename:'.github/AGENTS.md',status:'modified'}]};
        if (path.endsWith('/rulesets?per_page=100')) return [{id:1,name,enforcement:'active',target:'branch'}];
        if (path.endsWith('/rulesets/1')) return {id:1,name,enforcement:'active',target:'branch',source:f.repository,
            bypass_actors:[],current_user_can_bypass:'never',conditions:{ref_name:{include:['refs/heads/main'],exclude:[]}},rules};
        if (path.endsWith('/rules/branches/main')) return rules;
        if (path.endsWith('/actions/runs/100/attempts/1/jobs?per_page=100&page=1')) return {total_count:jobs.length,jobs};
        if (path.endsWith('/commits/'+env.CANDIDATE_SHA)) return {sha:env.CANDIDATE_SHA,parents:[{sha:env.SOURCE_BASE}],commit:{tree:{sha:f.expected.tree}}};
        throw new Error('Unexpected CI installation fixture: '+path);
    };
    assert.equal(qualifyCiInstallation(env,call).native_owners.length,29,'CI main installation retains FULL plus release audit');
    jobs.at(-1).conclusion='failure';
    assert.throws(()=>qualifyCiInstallation(env,call),/BLOCKED/,'a failed release audit prevents any PR qualification');
    jobs.at(-1).conclusion='success';
    assert.throws(()=>qualifyCiInstallation({...env,GITHUB_ACTOR:'attacker'},call),/BLOCKED/);
    assert.throws(()=>qualifyCiInstallation(env,path=>path.includes('/compare/')
        ?{...call(path),ahead_by:2}:call(path)),/BLOCKED/,'FULL installation cannot accept an unsquashed feature');
    assert.throws(()=>qualifyCiInstallation(env,path=>path.endsWith('/commits/'+env.CANDIDATE_SHA)
        ?{...call(path),parents:[{sha:env.SOURCE_BASE},{sha:'8'.repeat(40)}]}:call(path)),/BLOCKED/,'the final candidate has exactly one parent at the frozen main base');
    assert.throws(()=>qualifyCiInstallation(env,path=>path.includes('/compare/')
        ?{...call(path),files:[{filename:'app/controllers/unreviewed.php'}]}:call(path)),/BLOCKED/,'CI installation cannot smuggle product changes into main');
}
