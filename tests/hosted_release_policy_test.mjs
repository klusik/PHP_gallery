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
import {createHash} from 'node:crypto';
import {readFileSync} from 'node:fs';
import {validateRequest,verifyQualification,verifyFinalContent,requireQualificationChecks,fetchReleases,fetchJobs} from '../.github/scripts/release-promotion.mjs';
import {requireOwnerDispatch,requireOwnerEnvironment,requireOwnerRuleset,requireBotPullRequest,effectiveOwnerReview,integrationCheckBinding} from '../.github/scripts/release-owner-authorization.mjs';
import {dispatchPublishedWebsite} from '../.github/scripts/project-website-dispatch.mjs';
import {verifyAutomaticCompletion} from '../.github/scripts/release-reconciliation.mjs';
const read=path=>readFileSync(new URL('../'+path,import.meta.url),'utf8');
{
    const publication={repository:'owner/gallery',tag:'v_1.2.3'};
    const context={repository:publication.repository,eventName:'release',ref:'refs/tags/v_1.2.3'};
    const writes=[];
    const published={id:10,tag_name:publication.tag,draft:false,prerelease:false};
    const provider=(path,method='GET',payload=null)=>{
        if (method!=='GET') {writes.push({path,method,payload});return null;}
        if (path.endsWith('/releases/latest')) return published;
        if (path.endsWith('/pages')) return {build_type:'workflow'};
        if (path.endsWith('/git/ref/heads/main')) return {ref:'refs/heads/main',object:{type:'commit',sha:'a'.repeat(40)}};
        if (path.includes('/contents/.github/workflows/project-website.yml?ref=')) return {
            type:'file',path:'.github/workflows/project-website.yml',sha:'b'.repeat(40)};
        throw new Error('Unexpected website handoff endpoint '+path);
    };
    assert.equal(dispatchPublishedWebsite(publication,context,provider).state,'DISPATCHED');
    assert.deepEqual(writes,[{path:'repos/owner/gallery/actions/workflows/project-website.yml/dispatches',method:'POST',payload:{ref:'main'}}]);
    writes.length=0;
    for (const invalid of [{ref:'refs/heads/main'},{eventName:'workflow_dispatch'},{repository:'fork/gallery'}]) {
        assert.throws(()=>dispatchPublishedWebsite(publication,{...context,...invalid},provider),/BLOCKED/);
    }
    assert.equal(dispatchPublishedWebsite(publication,context,path=>path.endsWith('/releases/latest')
        ? {...published,tag_name:'v_1.2.4'} : provider(path)).state,'SUPERSEDED');
    assert.throws(()=>dispatchPublishedWebsite(publication,context,path=>path.endsWith('/pages')
        ? {build_type:'legacy'} : provider(path)),/Source to GitHub Actions/);
    for (const patch of [{draft:true},{prerelease:true},{id:0}]) {
        assert.throws(()=>dispatchPublishedWebsite(publication,context,path=>path.endsWith('/releases/latest')
            ? {...published,...patch} : provider(path)),/BLOCKED/);
    }
    assert.equal(writes.length,0,'invalid, legacy-configured and superseded publications cannot dispatch or deploy');
    const yaml=read('.github/workflows/project-website.yml');
    assert.match(yaml,/name: Deploy reviewed website to Pages[\s\S]*if: \$\{\{ github\.ref == 'refs\/heads\/main' \}\}/);
    assert.match(yaml,/name: Build and verify website\s+if: \$\{\{ github\.event_name != 'release' \}\}/);
    const handoff=yaml.slice(yaml.indexOf('  refresh-main:'),yaml.indexOf('  build:'));
    assert.match(handoff,/actions: write/);
    assert.doesNotMatch(handoff,/pages: write|id-token: write|deploy-pages/);
    assert.ok(handoff.includes('project-website-dispatch.mjs'));
}
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
const schema2Record={...record,lifecycle_schema:2,winapp_changed:false};
const schema2Jobs=[...jobs,...['Prepare manual release assets','Open qualified release PR']
    .map(name=>({name,status:'completed',conclusion:'success',html_url:'https://github.com/owner/gallery/actions/runs/123'}))];
assert.deepEqual(verifyQualification(request,run,schema2Jobs,schema2Record,record.repository,candidate),[]);
assert.throws(()=>verifyQualification(request,run,schema2Jobs.slice(0,-1),schema2Record,record.repository,candidate),/missing/);
assert.deepEqual(verifyQualification(request,run,[...schema2Jobs,{name:'Prepare Windows companion installer',status:'completed',
    conclusion:'skipped',html_url:'https://github.com/owner/gallery/actions/runs/123'}],schema2Record,record.repository,candidate),[]);
const bootstrapPrefix='Run first-install full release lifecycle / ';
const workflowJob=(name,id,status='completed',conclusion='success')=>({id,name,status,conclusion,
    workflow_name:'Release qualification',run_id:123,run_attempt:1,html_url:'https://github.com/owner/gallery/actions/runs/123'});
const schema2BootstrapJobs=[workflowJob('Select release coverage owner',150),
    ...schema2Jobs.map((job,index)=>workflowJob(bootstrapPrefix+job.name,200+index)),
    workflowJob(bootstrapPrefix+'Prepare Windows companion installer',250,'completed','skipped'),
    ...['Prepare release candidate','Basic release delta checks','Release qualification gate','Prepare manual release assets',
        'Prepare Windows companion installer','Open qualified release PR'].map((name,index)=>workflowJob(name,300+index,'completed','skipped'))];
assert.deepEqual(verifyQualification(request,run,schema2BootstrapJobs,schema2Record,record.repository,candidate),[]);
assert.throws(()=>verifyQualification(request,run,schema2BootstrapJobs.filter(job=>job.name!=='Select release coverage owner'),
    schema2Record,record.repository,candidate),/first-install bootstrap selection/);
assert.throws(()=>verifyQualification(request,run,[...schema2BootstrapJobs,
    workflowJob(bootstrapPrefix+'Unexpected extra job',400)],schema2Record,record.repository,candidate),/unknown or foreign nested job/);
assert.throws(()=>verifyQualification(request,run,schema2BootstrapJobs.map(job=>job.name==='Basic release delta checks'
    ? {...job,conclusion:'success'} : job),schema2Record,record.repository,candidate),/skipped strict-path inventory/);
// Schema 3 binds BASIC-only release coverage to one authenticated full-A integration proof.
const releaseA=record.selected_develop_sha;
const schema3Record={...record,lifecycle_schema:3,coverage_schema:1,winapp_changed:false,installer_required:false,
    integration:{candidate_sha:releaseA,candidate_tree:'4'.repeat(40),run_id:'456',run_attempt:'1',
        workflow_sha:'5'.repeat(40),workflow_path:'.github/workflows/develop-integration.yml',check_run_id:401,
        artifact_id:501,artifact_sha256:'sha256:'+'a'.repeat(64)},
    delta:{base_sha:releaseA,candidate_sha:candidate,profile:'basic',artifact_id:502,
        artifact_sha256:'sha256:'+'b'.repeat(64)}};
const fullOwnerNames=['Read-only generated-state and source preflight',
    'Positive production package (ubuntu-24.04)','Positive production package (windows-2025)',
    'Positive production package (macos-latest)','PHP 8.3 workflows (mysql:8.4, Unicode)',
    'PHP 8.3 workflows (mariadb:10.11, Unicode)','PHP 8.5 workflows (mariadb:11.4, Unicode)',
    'PHP 8.1 source (unicode)','PHP 8.5 source (ascii)','Required Chromium fixtures','Complete required CI matrix'];
const manualDeltaPaths=['docs/PHP_Gallery_Manual.tex','docs/PHP_Gallery_Manual.pdf',
    'docs/PHP_Gallery_Manual_CZ.tex','docs/PHP_Gallery_Manual_CZ.pdf',
    'docs/PHP_Gallery_Manual_DE.tex','docs/PHP_Gallery_Manual_DE.pdf',
    'docs/PHP_Gallery_Manual_SV.tex','docs/PHP_Gallery_Manual_SV.pdf'];
const deltaPaths=['app/bootstrap.php','app/core-manifest.json','PATCH_NOTES.md','release-metadata.json',
    ...manualDeltaPaths,'.github/release-origins/v_1.2.3.json',
    '.github/release-predecessors/v_1.2.3.json'].sort();
const oldBootstrap=Buffer.from("<?php\nconst CMS_VERSION = '1.2.2';\nreturn true;\n");
const newBootstrap=Buffer.from("<?php\nconst CMS_VERSION = '1.2.3';\nreturn true;\n");
const gitBlobSha=bytes=>createHash('sha1').update(Buffer.concat([Buffer.from(`blob ${bytes.length}\0`),bytes])).digest('hex');
const deltaChanges=deltaPaths.map((path,index)=>({path,status:path.includes('/v_1.2.3.json')?'A':'M',
    old_blob:path==='app/bootstrap.php'?gitBlobSha(oldBootstrap):path.includes('/v_1.2.3.json')?'0'.repeat(40):String(index+10).repeat(40).slice(0,40),
    new_blob:path==='app/bootstrap.php'?gitBlobSha(newBootstrap):String(index+20).repeat(40).slice(0,40),
    old_mode:path.includes('/v_1.2.3.json')?'000000':'100644',new_mode:'100644'}));
schema3Record.delta.changed_paths=deltaPaths;
const integrationParent='8'.repeat(40),integrationSource='6'.repeat(40);
const integrationPayload={schema_version:1,repository:record.repository,source_branch:'feature/integration-fixture',
    source_sha:integrationSource,candidate_sha:releaseA,candidate_tree:'4'.repeat(40),expected_develop_sha:integrationParent,
    source_base:integrationParent,basic_profile:['php-lint','js-lint','source-documentation-changed'],workflow_sha:'5'.repeat(40),
    workflow_path:'.github/workflows/develop-integration.yml',run_id:'456',run_attempt:'1',
    native_owners:fullOwnerNames.map((name,index)=>({id:600+index,
        name:'Develop integration / '+name,status:'completed',conclusion:'success'}))};
const deltaPayload={schema_version:1,base_sha:releaseA,base_tree:'4'.repeat(40),candidate_sha:candidate,
    candidate_tree:'3'.repeat(40),version:'1.2.3',profile:'basic',successful:true,
    basic_report_sha256:'sha256:'+'c'.repeat(64),changes:deltaChanges};
const integrationBinding=`integration:456:1:${releaseA}`;
const integrationChecks=['Develop integration qualification','Complete required CI matrix'].map((name,index)=>({
    id:index===0?401:402,name,external_id:integrationBinding,app:{id:15368},head_sha:releaseA,status:'completed',conclusion:'success'}));
const schema3Call=path=>{
    if (path.endsWith('/actions/runs/456/attempts/1')) return {id:456,run_attempt:1,name:'Integrate into develop',
        path:'.github/workflows/develop-integration.yml',event:'workflow_dispatch',head_branch:'main',
        head_sha:'5'.repeat(40),status:'completed',conclusion:'success'};
    if (path.endsWith(`/commits/${releaseA}/check-runs?per_page=100&filter=all`))
        return {total_count:integrationChecks.length,check_runs:integrationChecks};
    if (path.endsWith(`/compare/${'5'.repeat(40)}...${'7'.repeat(40)}`))
        return {status:'ahead',merge_base_commit:{sha:'5'.repeat(40)}};
    if (path.endsWith(`/compare/${integrationSource}...${releaseA}`))
        return {base_commit:{sha:integrationSource},merge_base_commit:{sha:integrationSource},status:'ahead',ahead_by:1,behind_by:0,
            files:[{filename:'app/runtime/modules.php',status:'modified'}]};
    if (path.endsWith(`/git/ref/heads/main`)) return {ref:'refs/heads/main',object:{type:'commit',sha:'7'.repeat(40)}};
    if (path.endsWith(`/commits/${'5'.repeat(40)}`)) return {sha:'5'.repeat(40)};
    if (path.endsWith(`/commits/${integrationSource}`)) return {sha:integrationSource,parents:[{sha:integrationParent}]};
    if (path.endsWith(`/commits/${releaseA}`)) return {sha:releaseA,parents:[{sha:integrationSource}],commit:{tree:{sha:'4'.repeat(40)}}};
    if (path.endsWith(`/commits/${candidate}`)) return {sha:candidate,commit:{tree:{sha:'3'.repeat(40)}}};
    if (path.endsWith(`/git/trees/${'4'.repeat(40)}?recursive=1`)) return {sha:'4'.repeat(40),truncated:false,
        tree:deltaChanges.filter(change=>change.status!=='A').map(change=>({path:change.path,type:'blob',
            mode:change.old_mode,sha:change.old_blob}))};
    if (path.endsWith(`/git/trees/${'3'.repeat(40)}?recursive=1`)) return {sha:'3'.repeat(40),truncated:false,
        tree:deltaChanges.map(change=>({path:change.path,type:'blob',mode:change.new_mode,sha:change.new_blob}))};
    if (path.endsWith(`/git/blobs/${gitBlobSha(oldBootstrap)}`)) return {sha:gitBlobSha(oldBootstrap),encoding:'base64',
        size:oldBootstrap.length,content:oldBootstrap.toString('base64')};
    if (path.endsWith(`/git/blobs/${gitBlobSha(newBootstrap)}`)) return {sha:gitBlobSha(newBootstrap),encoding:'base64',
        size:newBootstrap.length,content:newBootstrap.toString('base64')};
    assert.fail('Unexpected schema 3 fixture GET '+path);
};
const schema3Adapters={api:schema3Call,
    integrationEvidence:{payload:integrationPayload,provider:{artifact_id:501,artifact_digest:schema3Record.integration.artifact_sha256,run_id:'456'}},
    deltaEvidence:{payload:deltaPayload,provider:{artifact_id:502,artifact_digest:schema3Record.delta.artifact_sha256,run_id:'123'}},
    integrationJobs:[...integrationPayload.native_owners,
        ...['Select squashed source','Prepare integration artifacts','Publish prepared integration candidate','Publish develop integration']
            .map((name,index)=>({id:700+index,name,status:'completed',conclusion:'success'})),
        {id:710,name:'Develop integration / Authoritative release audit',status:'completed',conclusion:'skipped'}]
        .map(job=>({...job,workflow_name:'Integrate into develop',run_id:456,run_attempt:1,
            html_url:'https://github.com/owner/gallery/actions/runs/456'}))};
const basicJobs=[workflowJob('Select release coverage owner',800),
    ...['Prepare release candidate','Basic release delta checks','Release qualification gate',
        'Prepare manual release assets','Open qualified release PR']
        .map((name,index)=>workflowJob(name,801+index)),
    workflowJob('Prepare Windows companion installer',806,'completed','skipped'),
    workflowJob('Run first-install full release lifecycle',807,'completed','skipped'),
    ...['Prepare release candidate','Release qualification gate','Prepare manual release assets',
        'Prepare Windows companion installer','Open qualified release PR',
        'Complete release matrix / Complete required CI matrix']
        .map((name,index)=>workflowJob(bootstrapPrefix+name,900+index,'completed','skipped'))];
assert.deepEqual(verifyQualification(request,run,basicJobs,schema3Record,record.repository,candidate,schema3Adapters),[]);
assert.throws(()=>verifyQualification(request,run,basicJobs,schema2Record,record.repository,candidate),
    /first-install bootstrap selection or skipped strict-path inventory is incomplete/);
assert.throws(()=>verifyQualification(request,run,basicJobs,{...schema3Record,installer_required:true},record.repository,candidate,schema3Adapters),
    /Windows companion installer outcome differs from the strict release policy/);
assert.deepEqual(verifyQualification(request,run,basicJobs.map(job=>job.name==='Prepare Windows companion installer'
    ? {...job,conclusion:'success'} : job),
    {...schema3Record,installer_required:true},record.repository,candidate,schema3Adapters),[]);
assert.deepEqual(integrationCheckBinding(integrationBinding),{kind:'integration',runId:'456',attempt:1,sha:releaseA});
assert.equal(integrationCheckBinding('release:456:1:'+releaseA),null);
assert.throws(()=>verifyQualification(request,run,basicJobs,schema3Record,record.repository,candidate),/artifact|BLOCKED/);
for (const adverse of [
    {...schema3Adapters,integrationEvidence:{...schema3Adapters.integrationEvidence,provider:{...schema3Adapters.integrationEvidence.provider,artifact_digest:'sha256:'+'d'.repeat(64)}}},
    {...schema3Adapters,deltaEvidence:{...schema3Adapters.deltaEvidence,payload:{...deltaPayload,profile:'full'}}},
    {...schema3Adapters,deltaEvidence:{...schema3Adapters.deltaEvidence,payload:{...deltaPayload,changes:[...deltaChanges,{...deltaChanges[0],path:'tests/unauthorized.php'}]}}},
    {...schema3Adapters,integrationJobs:[...schema3Adapters.integrationJobs,
        {id:711,name:'Unrecognized successful job',workflow_name:'Integrate into develop',run_id:456,run_attempt:1,
            status:'completed',conclusion:'success'}]},
    {...schema3Adapters,integrationJobs:schema3Adapters.integrationJobs.filter(job=>job.name!=='Prepare integration artifacts')},
]) assert.throws(()=>verifyQualification(request,run,basicJobs,schema3Record,record.repository,candidate,adverse),/BLOCKED/);
assert.throws(()=>verifyQualification(request,run,basicJobs,{...schema3Record,coverage_schema:2},record.repository,candidate,schema3Adapters),/unsupported release coverage schema/);
const alteredBootstrap=Buffer.from("<?php\nconst CMS_VERSION = '1.2.3';\nreturn false;\n");
const alteredBootstrapSha=gitBlobSha(alteredBootstrap);
const alteredPayload={...deltaPayload,changes:deltaChanges.map(change=>change.path==='app/bootstrap.php'
    ? {...change,new_blob:alteredBootstrapSha} : change)};
assert.throws(()=>verifyQualification(request,run,basicJobs,schema3Record,record.repository,candidate,{
    ...schema3Adapters,deltaEvidence:{...schema3Adapters.deltaEvidence,payload:alteredPayload},
    api:path=>path.endsWith(`/git/blobs/${alteredBootstrapSha}`)
        ? {sha:alteredBootstrapSha,encoding:'base64',size:alteredBootstrap.length,content:alteredBootstrap.toString('base64')}
        : path.endsWith(`/git/trees/${'3'.repeat(40)}?recursive=1`)
            ? {sha:'3'.repeat(40),truncated:false,tree:alteredPayload.changes.map(change=>({path:change.path,
                type:'blob',mode:change.new_mode,sha:change.new_blob}))}
        : schema3Call(path),
}),/CMS_VERSION literal projection/);
assert.throws(()=>verifyQualification(request,run,basicJobs,schema3Record,record.repository,candidate,{
    ...schema3Adapters,api:path=>path.endsWith(`/git/trees/${'3'.repeat(40)}?recursive=1`)
        ? {...schema3Call(path),tree:[...schema3Call(path).tree,{path:'app/services/hidden-change.php',
            type:'blob',mode:'100644',sha:'2'.repeat(40)}]} : schema3Call(path),
}),/omits or invents/);
assert.throws(()=>verifyQualification(request,run,[...basicJobs,workflowJob('PHP 8.1 source (unicode)',1300)],
    schema3Record,record.repository,candidate,schema3Adapters),/full functional/);
assert.throws(()=>verifyQualification(request,run,basicJobs.map(job=>job.name==='Basic release delta checks'
    ? {...job,workflow_name:'Foreign workflow'} : job),schema3Record,record.repository,candidate,schema3Adapters),
    /missing\/ambiguous required BASIC release job/);
assert.throws(()=>verifyQualification(request,run,[...basicJobs,workflowJob('Unrecognized successful job',1400)],
    schema3Record,record.repository,candidate,schema3Adapters),/unknown strict release qualification job/);
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
    if (path.endsWith('/rulesets?per_page=100')) return [{id:24808772,name:server.ruleset.name,
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
    state=>{state.id=24808773;},
    state=>{state.source='intruder/gallery';},
    state=>{state.target='tag';},
    state=>{state.current_user_can_bypass='always';},
    state=>{state.bypass_actors=null;},
    state=>{state.bypass_actors=[{actor_id:1,actor_type:'RepositoryRole',bypass_mode:'always'}];},
    state=>{state.rules.find(rule=>rule.type==='pull_request').parameters.require_last_push_approval=true;},
    state=>{state.rules.find(rule=>rule.type==='required_status_checks').parameters.required_status_checks=[];},
    state=>{state.rules.find(rule=>rule.type==='required_status_checks').parameters.required_status_checks.push({context:'Foreign CI',integration_id:1});},
    state=>{state.rules.find(rule=>rule.type==='pull_request').parameters.require_code_owner_review=true;},
    state=>{state.rules.find(rule=>rule.type==='pull_request').parameters.require_extra_approval_for_unattributed_changes=false;},
    state=>{state.rules.find(rule=>rule.type==='pull_request').parameters.required_reviewers=[{reviewer_id:1,reviewer_type:'Team'}];},
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
assert.equal(requireOwnerRuleset(ownerCall,ownerRepo,'main',ownerRule.name,'Release qualification').bypass_inventory,
    'NOT_RETURNED_SERVER_ENFORCED');
assert.equal(Object.hasOwn(server.ruleset,'bypass_actors'),false,'Redacted fields must not be fabricated.');
const effectiveMismatch=path=>path.endsWith('/rules/branches/main')
    ?server.ruleset.rules.filter(rule=>rule.type!=='non_fast_forward'):ownerCall(path);
assert.throws(()=>requireOwnerRuleset(effectiveMismatch,ownerRepo,'main',ownerRule.name,'Release qualification'),/BLOCKED/);
server.ruleset=structuredClone(ownerRule);
const botPr={number:7,user:{login:'github-actions[bot]'},head:{sha:candidate,
    repo:{full_name:ownerRepo}},base:{ref:'main'},created_at:'2026-10-07T15:00:00Z',
merged_at:'2026-10-08T00:00:00Z'};
requireBotPullRequest(botPr,ownerRepo,candidate,'main');
requireBotPullRequest({...botPr,auto_merge:{merge_method:'merge',enabled_by:{login:'github-actions[bot]'}}},ownerRepo,candidate,'main');
for (const auto_merge of [{merge_method:'squash',enabled_by:{login:'github-actions[bot]'}},
    {merge_method:'rebase',enabled_by:{login:'owner'}},{merge_method:'merge',enabled_by:{login:'intruder'}}]) {
    assert.throws(()=>requireBotPullRequest({...botPr,auto_merge},ownerRepo,candidate,'main'),/BLOCKED/);
}
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
for (const conclusion of ['success','failure',null]) {
    const newer={...boundChecks[0],id:2,external_id:'release:124:1:'+candidate,conclusion};
    assert.throws(()=>requireQualificationChecks(()=>({total_count:3,check_runs:[...boundChecks,newer]}),record.repository,request,'1'),/stale/,
        'A later exact-Q release check supersedes an earlier observer even if the older check remains green.');
}
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
// Provider failures permit only a complete smaller-page read of the same attempt.
const providerReads=[];
const smallPages=rawJobs.map(job=>({total_count:rawJobs.length,jobs:[job]}));
const providerRead=(executable,args)=>{
    providerReads.push({executable,args});
    if (args[1].endsWith('?per_page=100')) throw new Error('BLOCKED_API_PROVIDER: gh command failed (1).');
    return JSON.stringify(smallPages);
};
assert.deepEqual(fetchJobs(record.repository,'123',1,candidate,providerRead,readCheck),coverageJobs);
assert.equal(providerReads.length,2);
assert.deepEqual(providerReads[1],{executable:'gh',args:['api',
    'repos/'+record.repository+'/actions/runs/123/attempts/1/jobs?per_page=1','--paginate','--slurp']});
for (const reason of ['BLOCKED_API_PERMISSION: ','BLOCKED_API_NOT_FOUND: ','BLOCKED_COMMAND: ']) {
    let calls=0;
    assert.throws(()=>fetchJobs(record.repository,'123',1,candidate,()=>{
        calls+=1;throw new Error(reason+'gh command failed (1).');
    },readCheck),/BLOCKED/);
    assert.equal(calls,1,'Permission, missing-resource and unclassified failures never use fallback.');
}
let exhaustedReads=0;
assert.throws(()=>fetchJobs(record.repository,'123',1,candidate,()=>{
    exhaustedReads+=1;throw new Error('BLOCKED_API_PROVIDER: gh command failed (1).');
},readCheck),/BLOCKED_API_PROVIDER/);
assert.equal(exhaustedReads,2,'Repeated provider failure stops after one alternate read.');
assert.throws(()=>fetchJobs(record.repository,'123',1,candidate,(_executable,args)=>{
    if (args[1].endsWith('?per_page=100')) throw new Error('BLOCKED_API_PROVIDER: gh command failed (1).');
    return JSON.stringify(smallPages.slice(1));
},readCheck),/incomplete/,'Smaller pages cannot omit a required job.');
assert.throws(()=>verifyQualification(request,run,
    fetchJobs(record.repository,'123',1,candidate,providerRead,foreign),record,record.repository,candidate),
    /ambiguous/,'Foreign checks remain visible to the unchanged qualification gate.');
assert.throws(()=>verifyQualification({...request,override:true,reason:'outage',acceptedFailures:['Required Chromium fixtures']},run,jobs,record,record.repository,candidate),/overrides/);
const release={id:1,tag_name:'v_1.2',draft:false,prerelease:false,html_url:'https://example.test',assets:[]};
assert.equal(fetchReleases('owner/gallery',()=>JSON.stringify([[release],[{...release,id:2}]])).length,2);
assert.throws(()=>fetchReleases('owner/gallery',()=>JSON.stringify([{releases:[]}])) ,/malformed/);
const qualification=read('.github/workflows/release-qualification.yml');
const bootstrapWorkflow=read('.github/workflows/release-bootstrap.yml');
assert.match(qualification,/resolveIntegrationBootstrap\(repository,process\.env\.GITHUB_SHA/);
assert.match(qualification,/const bootstrap=workflow===null \|\| recovery!==null/);
assert.match(bootstrapWorkflow,/integration_recovery:JSON\.parse\(e\.INTEGRATION_RECOVERY \|\| 'null'\)/);
// Existing schema-2 consumers still require every native FULL/release owner for a repair release.
const recoveryRecord={...schema2Record,integration_recovery:{reason:'upload-artifact-digest-prefix'}};
assert.deepEqual(verifyQualification(request,run,schema2Jobs,recoveryRecord,record.repository,candidate),[]);
assert.throws(()=>verifyQualification(request,run,schema2Jobs.map(job=>job.name==='Required Chromium fixtures'
    ? {...job,conclusion:'skipped'} : job),recoveryRecord,record.repository,candidate),/BLOCKED/);
const completion=read('.github/workflows/release-promotion.yml');
assert.match(qualification,/Initialize or reuse immutable origin after release push/);
assert.doesNotMatch(qualification,/workflow_dispatch:|initialization_run:/);
assert.match(qualification,/name: Select release coverage owner[\s\S]*contents\/.github\/workflows\/develop-integration\.yml\?ref=\$\{mainSha\}/);
assert.match(qualification,/HTTP 404/);
assert.match(qualification,/name: Run first-install full release lifecycle[\s\S]*uses: \.\/\.github\/workflows\/release-bootstrap\.yml[\s\S]*secrets: inherit/);
assert.match(qualification,/name: Prepare release candidate\s+needs: coverage-owner\s+if: \$\{\{ needs\.coverage-owner\.outputs\.bootstrap == 'false' \}\}/);
assert.match(bootstrapWorkflow,/^on:\s+workflow_call:/m);
assert.doesNotMatch(bootstrapWorkflow,/^  push:/m);
assert.match(bootstrapWorkflow,/audit_profile: release/);
assert.match(bootstrapWorkflow,/lifecycle_schema:2/);
for (const manual of ['PHP_Gallery_Manual.tex','PHP_Gallery_Manual_CZ.tex','PHP_Gallery_Manual_DE.tex','PHP_Gallery_Manual_SV.tex']) {
    assert.ok(bootstrapWorkflow.includes(manual),`Bootstrap preserves full legacy manual generation: ${manual}`);
}
assert.match(bootstrapWorkflow,/name: Prepare Windows companion installer/);
assert.match(bootstrapWorkflow,/name: Open qualified release PR/);
assert.match(completion,/pull_request_review:/);
assert.match(completion,/types: \[submitted\]/);
assert.match(completion,/Bind successful server run and retained Q record before checkout/);
assert.match(completion,/record\.candidate_sha!==e\.CANDIDATE_SHA/);
assert.match(completion,/workflow_dispatch:/);
assert.match(completion,/inputs.mode == 'complete' && startsWith\(github.ref, 'refs\/heads\/release\/v_'\)/);
assert.match(completion,/e.GITHUB_SHA!==e.CANDIDATE_SHA/);
assert.ok(completion.includes("e.REQUESTED_RUN!==checks[0].external_id.split(':')[1]"));
assert.doesNotMatch(completion,/environment:|gh release create|gh release edit|merge_method:'squash'/);
assert.match(qualification,/manual-release-assets/);
assert.match(read('.github/scripts/release-completion.mjs'),/merge_method:'merge'/);
assert.doesNotMatch(read('.github/scripts/release-completion.mjs'),/release','create|release','edit|force-with-lease/);
{
    const approved={id:44,reviewer:'owner',commit_sha:candidate,submitted_at:'2026-10-02T00:00:00Z',url:null};
    const receipt={...schema3Record,final_main_sha:'9'.repeat(40),promotion_pr:{number:99,reviewed_sha:candidate,
        merge_commit_sha:'9'.repeat(40),merged_at:'2026-10-03T00:00:00Z',approval:approved}};
    const provider=path=>{
        if (path.endsWith('/actions/runs/123')) return {...run,id:123};
        if (path.endsWith('/commits/'+candidate+'/check-runs?per_page=100&filter=all')) return {total_count:2,
            check_runs:['Release qualification','Complete required CI matrix'].map((name,index)=>({id:index+10,
                name,app:{id:15368},head_sha:candidate,status:'completed',conclusion:'success',
                external_id:'release:123:1:'+candidate}))};
        if (path.endsWith('/pulls/99/reviews?per_page=100')) return [{id:approved.id,user:{login:'owner'},
            state:'APPROVED',commit_id:candidate,submitted_at:approved.submitted_at}];
        if (path.endsWith('/pulls/99')) return {number:99,user:{login:'github-actions[bot]'},state:'closed',merged:true,
            head:{sha:candidate,ref:request.branch,repo:{full_name:ownerRepo}},base:{ref:'main',repo:{full_name:ownerRepo}},
            merge_commit_sha:receipt.final_main_sha,merged_at:receipt.promotion_pr.merged_at,created_at:'2026-10-01T00:00:00Z',
            merged_by:{login:'github-actions[bot]'}};
        if (path.includes('/rulesets') || path.endsWith('/rules/branches/main')) return ownerCall(path);
        return schema3Call(path);
    };
    const adapters={...schema3Adapters,qualificationRecord:schema3Record,jobs:basicJobs};
    assert.equal(verifyAutomaticCompletion(receipt,ownerRepo,provider,adapters),'https://github.com/owner/gallery/actions/runs/123');
    assert.throws(()=>verifyAutomaticCompletion(receipt,ownerRepo,provider,{...adapters,
        deltaEvidence:{...adapters.deltaEvidence,payload:{...deltaPayload,profile:'full'}}}),/BLOCKED/);
}
process.stdout.write('PASS exact qualification identities, complete coverage, no overrides, active policy and manual publication contracts\n');
