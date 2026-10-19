/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/hosted_release_policy_test.mjs
 * Module Type: Regression Test
 * Purpose: Refuse stale, partial and unaudited hosted release promotion without network writes.
 * Responsibilities:
 *   - Exercise real qualification validators with immutable synthetic server records
 *   - Protect CI-first instructions, branch triggers and isolated release credentials
 *   - Initialize a successor of an unpublished schema-3 release using authenticated FULL/BASIC ZIPs
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {maintenanceBridgeFixture} from './support/coverage_artifact_fixture.mjs';
import {requireCurrentMainBase} from '../.github/scripts/release-promotion.mjs';
import {createHash} from 'node:crypto';
import {readFileSync} from 'node:fs';
import {validateRequest,verifyQualification as verifyQualificationWithAdapters,verifyIntegrationEvidence,verifyFinalContent,requireQualificationChecks,fetchReleases,fetchJobs} from '../.github/scripts/release-promotion.mjs';
import {requireOwnerDispatch,requireOwnerEnvironment,requireOwnerRuleset,requireBotPullRequest,effectiveOwnerReview,integrationCheckBinding} from '../.github/scripts/release-owner-authorization.mjs';
import {dispatchPublishedWebsite} from '../.github/scripts/project-website-dispatch.mjs';
import {verifyAutomaticCompletion} from '../.github/scripts/release-reconciliation.mjs';
import {verifyPredecessor} from '../.github/scripts/release-origin.mjs';
import {startNewRelease} from '../.github/scripts/start-new-release.mjs';
import {coverageFixtureZip} from './support/coverage_artifact_fixture.mjs';
import {ciOwnerWorkflowResponse} from './support/ci_owner_fixture.mjs';
import {evidenceMatrixOwners,fullMatrixOwners,requireCurrentWorkflowOwners,requireIntegrationBasicProfile} from '../.github/scripts/ci-owner-contract.mjs';
const read=path=>readFileSync(new URL('../'+path,import.meta.url),'utf8');
/** Supply immutable synthetic workflow sources by retained lifecycle version to every release verification call.
 * @param {string} path Exact read-only source endpoint.
 * @param {number} version Historical owner contract selected from the retained record.
 * @param {string} workflowSha Exact synthetic workflow identity used by the run fixture.
 * @returns {object} Git-hashed workflow contents or main-history proof; unknown endpoints fail instead of using live network access.
 */
const releaseWorkflowFixture=(path,version,workflowSha)=>{
    const workflow=ciOwnerWorkflowResponse(path,version);
    if (workflow) return workflow;
    if (path.endsWith('/git/ref/heads/main')) return {ref:'refs/heads/main',object:{type:'commit',sha:workflowSha}};
    throw new Error('BLOCKED unexpected inert release workflow fixture GET '+path);
};
/** Route every release qualification through an inert workflow source unless the caller supplies its own explicit provider.
 * @param {object} request Authorized immutable release inputs.
 * @param {object} run Server-owned workflow attempt fixture.
 * @param {object[]} jobs Complete inert job inventory.
 * @param {object} record Retained release qualification record.
 * @param {string} repository Exact owner/repository identity.
 * @param {string} currentHead Observed release branch head.
 * @param {object} [adapters={}] Explicit artifact and provider fixtures; an explicit API provider overrides the default inert one.
 * @returns {object[]} Qualification result from the real release verifier.
 */
const verifyQualification=(request,run,jobs,record,repository,currentHead,adapters={})=>{
    const observed=new Set(jobs.map(job=>job.name?.split(' / ').at(-1)));
    const ownerVersion=record.lifecycle_schema===1?1:(record.lifecycle_schema===2?2:
        observed.has('Shared source contracts') || [...observed].some(name=>/shard \d+\/4\)$/.test(name))?3:
            ([...observed].some(name=>name.startsWith('PHP 8.3 '))?1:2));
    const workflowSha=run.head_sha ?? currentHead;
    const api=path=>releaseWorkflowFixture(path,ownerVersion,workflowSha);
    return verifyQualificationWithAdapters(request,run,jobs,record,repository,currentHead,{api,...adapters});
};
{
    const workflowSha='5'.repeat(40),mainSha='7'.repeat(40),repository='owner/gallery';
    const current=fullMatrixOwners().map(name=>({name}));
    const historicalV1=fullMatrixOwners(1).map(name=>({name}));
    const historicalV2=fullMatrixOwners(2).map(name=>({name}));
    const provider=(path,version=1)=>{
        const workflow=ciOwnerWorkflowResponse(path,version);
        if (workflow) return workflow;
        if (path.endsWith('/git/ref/heads/main')) return {ref:'refs/heads/main',object:{type:'commit',sha:mainSha}};
        if (path.endsWith(`/compare/${workflowSha}...${mainSha}`)) return {merge_base_commit:{sha:workflowSha}};
        assert.fail('Unexpected owner-contract fixture GET '+path);
    };
    assert.deepEqual(evidenceMatrixOwners(current,repository,workflowSha,path=>provider(path,3)),
        fullMatrixOwners());
    requireCurrentWorkflowOwners(repository,workflowSha,path=>provider(path,3));
    requireCurrentWorkflowOwners(repository,workflowSha,path=>provider(path,2),2);
    assert.throws(()=>requireCurrentWorkflowOwners(repository,workflowSha,path=>provider(path,2)),/exact requested owner matrix/,
        'A retained v2 source cannot silently satisfy the default live v3 owner contract.');
    assert.throws(()=>requireCurrentWorkflowOwners(repository,workflowSha,path=>provider(path,1),2),/exact requested owner matrix/);
    assert.deepEqual(evidenceMatrixOwners(historicalV1,repository,workflowSha,provider),fullMatrixOwners(1),
        'Historical PHP 8.3 owners remain valid only with complete Git-hashed W inherited from main.');
    assert.deepEqual(evidenceMatrixOwners(historicalV2,repository,workflowSha,path=>provider(path,2)),fullMatrixOwners(2),
        'Historical unsharded PHP 8.5 owners remain valid only with complete Git-hashed W inherited from main.');
    for (const rows of [
        current.filter(job=>job.name!=='PHP 8.5 workflows (mysql:8.4, Unicode, shard 2/4)'),
        current.filter(job=>job.name!=='Shared source contracts'),
        current.filter(job=>job.name!=='Portable Node and WinApp'),
        [...current,current[4]],
        current.map((job,index)=>index===8?historicalV2[4]:job)]) {
        assert.throws(()=>evidenceMatrixOwners(rows,repository,workflowSha,path=>provider(path,3)),/BLOCKED/,
            'Missing, duplicated and mixed shard/source/portable owner contracts never pass.');
    }
    assert.throws(()=>evidenceMatrixOwners(historicalV1,repository,workflowSha,path=>provider(path,3)),/workflow W/,
        'Current v3 W cannot authenticate historical v1 job names.');
    assert.throws(()=>evidenceMatrixOwners(historicalV2,repository,workflowSha,path=>provider(path,3)),/workflow W/,
        'Current v3 W cannot authenticate historical v2 job names.');
    assert.throws(()=>evidenceMatrixOwners(historicalV2,repository,undefined,path=>provider(path,2)),/workflow W/);
    assert.throws(()=>evidenceMatrixOwners(historicalV2,repository,workflowSha,path=>{
        const value=provider(path,2);
        return path.includes('/contents/')?{...value,content:Buffer.from('truncated').toString('base64')}:value;
    }),/Git object identity/);
    assert.throws(()=>evidenceMatrixOwners(historicalV2,repository,workflowSha,path=>
        path.includes('/compare/')?{merge_base_commit:{sha:'8'.repeat(40)}}:
            ciOwnerWorkflowResponse(path,path.endsWith('ref='+'8'.repeat(40))?1:2) || provider(path)),/inherited from main history/);
    requireIntegrationBasicProfile(['php-lint','js-lint','source-documentation-changed'],repository,workflowSha,provider);
    requireIntegrationBasicProfile(['php-lint-changed','js-lint-changed','source-documentation-changed'],repository,workflowSha,
        path=>provider(path,2));
    assert.throws(()=>requireIntegrationBasicProfile(['php-lint','js-lint','source-documentation-changed'],repository,workflowSha,
        path=>provider(path,2)),/BASIC profile differs/);
}
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
const run = {path:'.github/workflows/release-qualification.yml',event:'push',head_branch:request.branch,head_sha:candidate,
    status:'completed',conclusion:'success',run_attempt:1};
const record = {repository:'owner/gallery',branch:request.branch,candidate_sha:candidate,
    source_base:'b'.repeat(40),version:'1.2.3',run_id:'123',run_attempt:'1',ready:true,automated_result:'success',override:false,
    origin_commit_sha:'e'.repeat(40),selected_develop_sha:'f'.repeat(40),
    initial_main_sha:'9'.repeat(40),previous_stable_tag:'v_1.2.2',previous_stable_sha:'9'.repeat(40),
    previous_release_candidate_sha:'b'.repeat(40),previous_tag_object_sha:'9'.repeat(40),
    previous_tag_object_type:'commit',previous_tree_sha:'8'.repeat(40),predecessor_kind:'qualified',origin_blob_sha:'7'.repeat(40)};
const owners = ['Prepare release candidate','Read-only generated-state and source preflight',
    'Positive production package (ubuntu-24.04)','Positive production package (windows-2025)','Positive production package (macos-latest)',
    'PHP 8.5 workflows (mysql:8.4, Unicode)','PHP 8.5 workflows (mariadb:10.11, Unicode)','PHP 8.5 workflows (mariadb:11.4, Unicode)',
    'PHP 8.1 source (unicode)','PHP 8.5 source (ascii)','Required Chromium fixtures','Authoritative release audit','Complete required CI matrix','Release qualification gate'];
const jobs = owners.map(name => ({name,status:'completed',conclusion:'success',html_url:'https://github.com/owner/gallery/actions/runs/123'}));
assert.equal(validateRequest(request),'1.2.3');
verifyFinalContent(candidate,candidate,'b'.repeat(40),'b'.repeat(40));
assert.throws(() => verifyFinalContent('c'.repeat(40),candidate,'b'.repeat(40),'b'.repeat(40)),/main advanced/);
assert.throws(() => verifyFinalContent(candidate,candidate,'c'.repeat(40),'b'.repeat(40)),/tree differs/);
assert.deepEqual(verifyQualification(request,run,jobs,record,record.repository,candidate),[]);
{
    for (const lifecycle_schema of [1,2]) {
        const ownerVersion=lifecycle_schema===1?1:2;
        const workflowSha=(ownerVersion===1?'5':'6').repeat(40);
        const historicalOwners=fullMatrixOwners(ownerVersion);
        const currentOwnerSet=new Set([1,2,3].flatMap(version=>fullMatrixOwners(version)));
        const historicalJobs=[...jobs.filter(job=>!currentOwnerSet.has(job.name)),
            ...historicalOwners.map(name=>({name,status:'completed',conclusion:'success',
                html_url:'https://github.com/owner/gallery/actions/runs/123'}))];
        const historicalRun={...run,head_sha:workflowSha};
        const provider=path=>ciOwnerWorkflowResponse(path,ownerVersion) || (path.endsWith('/git/ref/heads/main')
            ? {ref:'refs/heads/main',object:{type:'commit',sha:workflowSha}} : assert.fail('Unexpected historical fixture '+path));
        const historicalRecord=lifecycle_schema===1?{...record,lifecycle_schema}:
            {...record,lifecycle_schema,winapp_changed:false};
        const owners=lifecycle_schema===1?historicalJobs:[...historicalJobs,
            ...['Prepare manual release assets','Open qualified release PR'].map(name=>({name,status:'completed',conclusion:'success'}))];
        assert.deepEqual(verifyQualification(request,historicalRun,owners,historicalRecord,record.repository,candidate,{api:provider}),[],
            'Retained lifecycle schema '+lifecycle_schema+' keeps its authenticated historical owner mapping.');
    }
}
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
    {previous_stable_tag:'v_1.2.3;bad'}]) {
    assert.throws(() => verifyQualification(request,run,jobs,{...record,...patch},record.repository,candidate),/provenance/);
}
assert.throws(()=>verifyQualification(request,run,jobs,{...record,previous_stable_sha:'c'.repeat(40)},record.repository,candidate,
    {api:path=>{
        if (path==='repos/'+record.repository+'/git/ref/tags/'+record.previous_stable_tag) {
            return {ref:'refs/tags/'+record.previous_stable_tag,object:{type:'commit',sha:record.previous_stable_sha}};
        }
        assert.equal(path,'repos/'+record.repository+'/commits/'+record.previous_stable_sha);
        return {sha:record.previous_stable_sha,commit:{tree:{sha:record.previous_tree_sha}}};
    }}),/stable tag moved/,'the stable SHA is checked against the actual Git tag, separately from record shape');
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
const fullOwnerNames=fullMatrixOwners();
const allMatrixOwnerNames=new Set([1,2,3].flatMap(version=>fullMatrixOwners(version)));
const integrationOwnerRows=(version,idStart)=>fullMatrixOwners(version).map((name,index)=>({
    id:idStart+index,name:'Develop integration / '+name,status:'completed',conclusion:'success'}));
const replaceIntegrationOwnerRows=(jobs,version,idStart)=>{
    const template=jobs.find(job=>allMatrixOwnerNames.has(job.name.split(' / ').at(-1)));
    assert.ok(template,'Historical server rows require their original workflow/run/attempt metadata.');
    return [...jobs.filter(job=>!allMatrixOwnerNames.has(job.name.split(' / ').at(-1))),
        ...integrationOwnerRows(version,idStart).map(owner=>({...template,...owner}))];
};
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
    source_base:integrationParent,basic_profile:['php-lint-changed','js-lint-changed','source-documentation-changed'],workflow_sha:'5'.repeat(40),
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
    const workflow=ciOwnerWorkflowResponse(path);
    if (workflow) return workflow;
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
for (const label of [
    'Develop integration / PHP 8.5 workflows (mysql:8.4, Unicode, shard 1/4)',
    'Develop integration / Shared source contracts',
    'Develop integration / Portable Node and WinApp']) {
    const owner=schema3Adapters.integrationJobs.find(job=>job.name===label);
    assert.ok(owner,'The exact v3 fixture must contain required owner '+label);
    assert.throws(()=>verifyIntegrationEvidence(schema3Record,record.repository,{...schema3Adapters,
        integrationJobs:schema3Adapters.integrationJobs.filter(job=>job!==owner)}),/BLOCKED/,
        'A missing shard/source/portable owner cannot qualify the exact candidate.');
    for (const conclusion of ['skipped','cancelled']) {
        assert.throws(()=>verifyIntegrationEvidence(schema3Record,record.repository,{...schema3Adapters,
            integrationJobs:schema3Adapters.integrationJobs.map(job=>job===owner?{...job,conclusion}:job)}),/BLOCKED/,
            'A '+conclusion+' shard/source/portable owner cannot qualify the exact candidate.');
    }
}
{
    const skipped={id:711,name:'Inherited BASIC authority',workflow_name:'Integrate into develop',run_id:456,
        run_attempt:1,status:'completed',conclusion:'skipped'};
    assert.doesNotThrow(()=>verifyIntegrationEvidence(schema3Record,record.repository,{...schema3Adapters,
        integrationJobs:[...schema3Adapters.integrationJobs,skipped]}),
        'Ordinary current-main integration can retain one explicitly skipped inherited BASIC job.');
    for (const patch of [{conclusion:'success'},{conclusion:'failure'},{status:'in_progress'},{run_attempt:2}]) {
        assert.throws(()=>verifyIntegrationEvidence(schema3Record,record.repository,{...schema3Adapters,
            integrationJobs:[...schema3Adapters.integrationJobs,{...skipped,...patch}]}),/BLOCKED/);
    }
    assert.throws(()=>verifyIntegrationEvidence(schema3Record,record.repository,{...schema3Adapters,
        integrationJobs:[...schema3Adapters.integrationJobs,skipped,{...skipped,id:712}]}),/BLOCKED/);
}
{
    for (const version of [1,2]) {
        const historicalWorkflowSha=(version===1?'91':'a2').repeat(20);
        const historicalRun={...run,head_sha:historicalWorkflowSha};
        const historicalApi=path=>{
            const workflow=ciOwnerWorkflowResponse(path,path.includes('/candidate-preparation.yml')?1:version);
            if (workflow) return workflow;
            if (path.endsWith(`/compare/${historicalWorkflowSha}...${'7'.repeat(40)}`)) {
                return {status:'ahead',merge_base_commit:{sha:historicalWorkflowSha}};
            }
            if (path.endsWith('/commits/'+historicalWorkflowSha)) return {sha:historicalWorkflowSha};
            if (path.endsWith('/actions/runs/456/attempts/1')) return {...schema3Call(path),head_sha:historicalWorkflowSha};
            return schema3Call(path);
        };
        const currentWorkflowApi=path=>ciOwnerWorkflowResponse(path)??historicalApi(path);
        const historicalIntegrationSha=historicalWorkflowSha;
        const historicalRecord={...schema3Record,integration:{...schema3Record.integration,workflow_sha:historicalIntegrationSha}};
        const historicalPayload={...integrationPayload,basic_profile:['php-lint','js-lint','source-documentation-changed'],
            workflow_sha:historicalIntegrationSha,native_owners:integrationOwnerRows(version,600)};
        const historical={...schema3Adapters,
            api:historicalApi,
            integrationEvidence:{...schema3Adapters.integrationEvidence,payload:historicalPayload},
            integrationJobs:replaceIntegrationOwnerRows(schema3Adapters.integrationJobs,version,600)};
        assert.deepEqual(verifyQualification(request,historicalRun,basicJobs,historicalRecord,record.repository,candidate,historical),[],
            'Schema 3 consumes authenticated historical FULL contract '+version+' without rewriting owner IDs or the source record.');
        assert.throws(()=>verifyQualification(request,historicalRun,basicJobs,historicalRecord,record.repository,candidate,
            {...historical,api:currentWorkflowApi}),/workflow W/,
            'Current v3 workflow bytes cannot authenticate historical FULL contract '+version+'.');
    }
}
// A protected-develop authority update terminates at one authentic original main-owned FULL archive.
for (const currentVersion of [2,3]) {
    const authority=releaseA,source='2'.repeat(40),integrated='1'.repeat(40),sourceTree='0'.repeat(40),integratedTree='e'.repeat(40);
    const checksum=bytes=>'sha256:'+createHash('sha256').update(bytes).digest('hex');
    const anchorPayload={...integrationPayload,basic_profile:['php-lint','js-lint','source-documentation-changed'],
        native_owners:integrationOwnerRows(2,600)};
    const originalArchive=coverageFixtureZip([['develop-integration.json',Buffer.from(JSON.stringify(anchorPayload))]]);
    const anchorIdentity={...schema3Record.integration,artifact_sha256:checksum(originalArchive)};
    const anchorEvidence={payload:anchorPayload,provider:{artifact_id:501,artifact_digest:checksum(originalArchive),run_id:'456'}};
    const identity={candidate_sha:integrated,candidate_tree:integratedTree,workflow_sha:authority,
        workflow_path:'.github/workflows/develop-integration.yml',run_id:'789',run_attempt:'1',check_run_id:1001,
        artifact_id:503,artifact_sha256:'sha256:'+'d'.repeat(64)};
    const basicReport=Buffer.from(JSON.stringify({schema_version:1,profile:'basic',status:'PASS',
        source:{head_sha:source,comparison_base:authority,worktree_dirty:false},
        tasks:['php-lint','js-lint','source-documentation-changed'].map(id=>({id,status:'PASS'}))}));
    const originalBasic={schema_version:1,authority_sha:authority,source_sha:source,source_tree:sourceTree,
        comparison_base:authority,profile:'basic',suites:['php-lint','js-lint','source-documentation-changed'],
        successful:true,report_sha256:checksum(basicReport),root_binding:'trusted-evaluator-candidate-root-v1'};
    const inheritedArchive=coverageFixtureZip([['trusted-basic.json',Buffer.from(JSON.stringify(originalBasic))],
        ['trusted-basic.json.report.json',basicReport]]);
    const payload={...integrationPayload,source_sha:source,candidate_sha:integrated,candidate_tree:integratedTree,
        expected_develop_sha:authority,source_base:authority,workflow_sha:authority,run_id:'789',
        native_owners:integrationOwnerRows(currentVersion,1200),authority_update:{anchor:{identity:anchorIdentity,evidence:anchorEvidence},
            inherited_basic:{...originalBasic,artifact_id:504,artifact_sha256:checksum(inheritedArchive)}}};
    const currentRun={id:789,run_attempt:1,name:'Integrate into develop',path:identity.workflow_path,event:'workflow_dispatch',
        head_branch:'develop',head_sha:authority,status:'completed',conclusion:'success',
        actor:{login:'owner'},triggering_actor:{login:'owner'}};
    const checks=['Develop integration qualification','Complete required CI matrix'].map((name,index)=>({id:1001+index,
        name,external_id:'integration:789:1:'+integrated,app:{id:15368},head_sha:integrated,status:'completed',conclusion:'success'}));
    const currentJobs=[...payload.native_owners,...['Select squashed source','Prepare integration artifacts',
        'Publish prepared integration candidate','Publish develop integration','Inherited BASIC authority'].map((name,index)=>({id:1300+index,
            name,status:'completed',conclusion:'success'})),{id:1310,name:'Develop integration / Authoritative release audit',
        status:'completed',conclusion:'skipped'}].map(job=>({...job,workflow_name:'Integrate into develop',run_id:789,run_attempt:1}));
    const provider=(path,method='GET')=>{
        assert.equal(method,'GET','Authority history readers must never write refs or metadata.');
        assert.ok(!path.endsWith('/git/ref/heads/develop') && !path.includes('/git/ref/heads/feature/'),
            'Historical authority validation does not depend on moving develop or feature refs.');
        if (path.endsWith('/actions/artifacts/501')) return {id:501,name:'develop-integration-record',expired:false,
            digest:checksum(originalArchive),size_in_bytes:originalArchive.length,workflow_run:{id:456}};
        if (path.endsWith('/actions/artifacts/504')) return {id:504,name:'develop-integration-inherited-basic',expired:false,
            digest:checksum(inheritedArchive),size_in_bytes:inheritedArchive.length,workflow_run:{id:789}};
        if (path.endsWith('/collaborators/owner/permission')) return {permission:'maintain'};
        if (path.endsWith('/actions/runs/789/attempts/1')) return currentRun;
        if (path.endsWith('/commits/'+integrated+'/check-runs?per_page=100&filter=all')) return {total_count:checks.length,check_runs:checks};
        if (path.endsWith('/commits/'+source)) return {sha:source,parents:[{sha:authority}],commit:{tree:{sha:sourceTree}}};
        if (path.endsWith('/commits/'+integrated)) return {sha:integrated,parents:[{sha:source}],commit:{tree:{sha:integratedTree}}};
        if (path.endsWith('/compare/'+authority+'...'+ '7'.repeat(40))) return {base_commit:{sha:authority},
            merge_base_commit:{sha:authority},status:'ahead',ahead_by:1,behind_by:0};
        if (path.endsWith('/compare/'+source+'...'+integrated)) return {base_commit:{sha:source},merge_base_commit:{sha:source},
            status:'ahead',ahead_by:1,behind_by:0,files:[{filename:'app/runtime/modules.php',status:'modified'}]};
        const currentSource=path.includes('?ref='+source);
        const workflowVersion=path.includes('/gallery-workflows.yml')
            ?(path.includes('?ref='+authority)?currentVersion:2):(currentSource?2:1);
        const workflow=ciOwnerWorkflowResponse(path,workflowVersion);
        if (workflow) {
            if (!workflow.path.endsWith('/candidate-preparation.yml')) return workflow;
            const text=Buffer.from(workflow.content,'base64').toString('utf8').replace(/php scripts\/audit\.php --profile=basic(?: --changed)?/g,
                'php scripts/audit.php --profile=basic'+(path.includes('?ref='+source)?' --changed':''));
            const bytes=Buffer.from(text);
            return {...workflow,sha:gitBlobSha(bytes),size:bytes.length,content:bytes.toString('base64')};
        }
        return schema3Call(path);
    };
    const authorityRecord={lifecycle_schema:3,coverage_schema:1,selected_develop_sha:integrated,integration:identity};
    const authorityAdapters={api:provider,integrationEvidence:{payload,provider:{artifact_id:503,
        artifact_digest:identity.artifact_sha256,run_id:'789'}},integrationJobs:currentJobs,
        anchorIntegrationJobs:replaceIntegrationOwnerRows(schema3Adapters.integrationJobs,2,600),
        anchorArtifacts:{download:(repository,id)=>{assert.equal(repository,record.repository);assert.equal(id,501);return originalArchive;}},
        inheritedBasicArtifacts:{download:(repository,id)=>{assert.equal(repository,record.repository);assert.equal(id,504);return inheritedArchive;}}};
    assert.deepEqual(verifyIntegrationEvidence(authorityRecord,record.repository,authorityAdapters),{
        candidate_sha:integrated,candidate_tree:integratedTree,workflow_sha:authority,run_id:'789',run_attempt:'1'},
        'The new FULL proof trusts protected develop W* only through the original main-owned archive and inherited whole BASIC.');
    const alteredPayload=mutate=>{
        const next=structuredClone(payload);mutate(next);
        return {...authorityAdapters,integrationEvidence:{...authorityAdapters.integrationEvidence,payload:next}};
    };
    for (const mutate of [value=>{delete value.authority_update;},value=>{value.authority_update=null;},
        value=>{value.authority_update.mode='bypass';},value=>{value.authority_update.anchor.approved=true;},
        value=>{value.authority_update.anchor.identity.candidate_sha=source;},
        value=>{value.authority_update.anchor.evidence.provider.artifact_digest='sha256:'+'0'.repeat(64);},
        value=>{value.authority_update.anchor.evidence.payload.source_branch='feature/invented';},
        value=>{value.authority_update.anchor.evidence.payload.authority_update=null;},
        value=>{delete value.authority_update.inherited_basic;},value=>{value.authority_update.inherited_basic.source_sha=integrated;},
        value=>{value.authority_update.inherited_basic.source_tree=integratedTree;},
        value=>{value.authority_update.inherited_basic.authority_sha=source;},
        value=>{value.authority_update.inherited_basic.comparison_base=integrationParent;},
        value=>{value.authority_update.inherited_basic.profile='full';},
        value=>{value.authority_update.inherited_basic.suites=['php-lint-changed','js-lint-changed','source-documentation-changed'];},
        value=>{value.authority_update.inherited_basic.successful=false;},
        value=>{value.authority_update.inherited_basic.report_sha256='b'.repeat(64);},
        value=>{delete value.authority_update.inherited_basic.artifact_id;},
        value=>{value.authority_update.inherited_basic.artifact_id=0;},
        value=>{value.authority_update.inherited_basic.artifact_sha256='b'.repeat(64);},
        value=>{value.authority_update.inherited_basic.root_binding='candidate-evaluator';},
        value=>{value.authority_update.inherited_basic.override=true;},
        value=>{value.basic_profile=['php-lint','js-lint','source-documentation-changed'];},
        value=>{value.recovery_mode='existing-develop';},value=>{value.override=false;}]) {
        assert.throws(()=>verifyIntegrationEvidence(authorityRecord,record.repository,alteredPayload(mutate)),/BLOCKED/);
    }
    for (const patch of [{head_branch:'main'},{actor:{login:'someone'}},{triggering_actor:{login:'someone'}},
        {actor:null},{triggering_actor:null},{head_sha:source},{event:'push'}]) {
        assert.throws(()=>verifyIntegrationEvidence(authorityRecord,record.repository,{...authorityAdapters,
            api:path=>path.endsWith('/actions/runs/789/attempts/1')?{...currentRun,...patch}:provider(path)}),/BLOCKED/);
    }
    assert.throws(()=>verifyIntegrationEvidence(authorityRecord,record.repository,{...authorityAdapters,
        api:path=>path.endsWith('/collaborators/owner/permission')?{permission:'write'}:provider(path)}),/BLOCKED/);
    assert.throws(()=>verifyIntegrationEvidence(authorityRecord,record.repository,{...authorityAdapters,
        api:path=>path.endsWith('/actions/runs/456/attempts/1')?{...schema3Call(path),head_branch:'develop'}:provider(path)}),/BLOCKED/,
        'A nested develop-owned authority cannot impersonate the terminating original main-owned anchor.');
    assert.throws(()=>verifyIntegrationEvidence(authorityRecord,record.repository,{...authorityAdapters,
        api:path=>path.endsWith('/actions/artifacts/501')?{...provider(path),expired:true}:provider(path)}),/BLOCKED/);
    assert.throws(()=>verifyIntegrationEvidence(authorityRecord,record.repository,{...authorityAdapters,
        anchorArtifacts:{download:()=>Buffer.from('truncated anchor archive')}}),/BLOCKED/);
    for (const patch of [{name:'develop-integration-prepared-state'},{workflow_run:{id:456}},{expired:true},
        {digest:'sha256:'+'0'.repeat(64)},{size_in_bytes:inheritedArchive.length+1}]) {
        assert.throws(()=>verifyIntegrationEvidence(authorityRecord,record.repository,{...authorityAdapters,
            api:path=>path.endsWith('/actions/artifacts/504')?{...provider(path),...patch}:provider(path)}),/BLOCKED/);
    }
    const swappedBasic=(reportBytes,archiveEvidence=originalBasic,recordEvidence=archiveEvidence)=>{
        const archive=coverageFixtureZip([['trusted-basic.json',Buffer.from(JSON.stringify(archiveEvidence))],
            ['trusted-basic.json.report.json',reportBytes]]);
        const retained=structuredClone(payload);
        retained.authority_update.inherited_basic={...recordEvidence,artifact_id:504,artifact_sha256:checksum(archive)};
        return {...authorityAdapters,integrationEvidence:{...authorityAdapters.integrationEvidence,payload:retained},
            inheritedBasicArtifacts:{download:()=>archive},api:path=>path.endsWith('/actions/artifacts/504')
                ?{...provider(path),digest:checksum(archive),size_in_bytes:archive.length}:provider(path)};
    };
    assert.throws(()=>verifyIntegrationEvidence(authorityRecord,record.repository,
        swappedBasic(Buffer.concat([basicReport,Buffer.from('\n')]))),/BLOCKED/,
        'Independent raw report bytes must reproduce report_sha256 even when decoded PASS semantics are unchanged.');
    assert.throws(()=>verifyIntegrationEvidence(authorityRecord,record.repository,
        swappedBasic(basicReport,{...originalBasic,source_tree:integratedTree},originalBasic)),/BLOCKED/,
        'Provider-authenticated evidence cannot substitute different ten-field bytes for the retained record.');
    for (const mutate of [value=>{value.status='FAIL';},value=>{value.source.head_sha=integrated;},
        value=>{value.source.comparison_base=integrationParent;},value=>{value.source.worktree_dirty=true;},
        value=>{value.tasks[0].status='FAIL';},value=>{value.tasks[0].id='php-lint-changed';},
        value=>{value.tasks.pop();}]) {
        const report=JSON.parse(basicReport.toString('utf8'));mutate(report);
        const bytes=Buffer.from(JSON.stringify(report));
        assert.throws(()=>verifyIntegrationEvidence(authorityRecord,record.repository,
            swappedBasic(bytes,{...originalBasic,report_sha256:checksum(bytes)})),/BLOCKED/,
            'Matching archive and report digests do not authorize a failed, dirty, stale or partial original BASIC report.');
    }
    for (const transform of [rows=>rows.filter(job=>job.name!=='Inherited BASIC authority'),
        rows=>rows.map(job=>job.name==='Inherited BASIC authority'?{...job,conclusion:'failure'}:job),
        rows=>rows.map(job=>job.name==='Inherited BASIC authority'?{...job,conclusion:'skipped'}:job),
        rows=>rows.map(job=>job.name==='Inherited BASIC authority'?{...job,run_attempt:2}:job),
        rows=>[...rows,{...rows.find(job=>job.name==='Inherited BASIC authority'),id:1311}]]) {
        assert.throws(()=>verifyIntegrationEvidence(authorityRecord,record.repository,
            {...authorityAdapters,integrationJobs:transform(currentJobs)}),/BLOCKED/,
            'Authority updates require exactly one successful independent native BASIC job from this attempt.');
    }
    assert.throws(()=>verifyIntegrationEvidence(authorityRecord,record.repository,{...authorityAdapters,
        api:path=>path.includes('/contents/.github/workflows/gallery-workflows.yml?ref='+authority)
            ?ciOwnerWorkflowResponse(path,1):provider(path)}),/BLOCKED/,
        'Current native labels cannot replace the authenticated PHP 8.5 matrix source at trusted W*.');
    assert.throws(()=>verifyIntegrationEvidence(authorityRecord,record.repository,{...authorityAdapters,
        integrationJobs:currentJobs.map(job=>job.name.endsWith('Required Chromium fixtures')?{...job,conclusion:'failure'}:job)}),/BLOCKED/);
    assert.throws(()=>verifyIntegrationEvidence(authorityRecord,record.repository,{...authorityAdapters,
        anchorIntegrationJobs:authorityAdapters.anchorIntegrationJobs.filter(job=>!job.name.endsWith('Required Chromium fixtures'))}),/BLOCKED/);
    assert.throws(()=>verifyIntegrationEvidence(authorityRecord,record.repository,{...authorityAdapters,
        api:path=>path.includes('/contents/.github/workflows/candidate-preparation.yml?ref='+source)
            ?ciOwnerWorkflowResponse(path,1):provider(path)}),/BLOCKED/,
        'Feature BASIC remains changed-source at S even though the inherited W* evaluator intentionally runs whole BASIC.');
}
// Start a second release while its schema-3 predecessor is tagged but still unpublished.
// Real ZIP bytes exercise the production coverage loader, not prehydrated payload adapters.
{
    const checksum=bytes=>'sha256:'+createHash('sha256').update(bytes).digest('hex');
    const report=Buffer.from(JSON.stringify({schema_version:1,profile:'basic',status:'PASS',
        source:{head_sha:candidate,comparison_base:releaseA,worktree_dirty:false},
        tasks:['php-lint-changed','js-lint-changed','source-documentation-changed'].map(id=>({id,status:'PASS'}))}));
    const predecessor=structuredClone(schema3Record);
    const retainedDelta={...deltaPayload,basic_report_sha256:checksum(report)};
    const archives=new Map([
        [501,coverageFixtureZip([['develop-integration.json',Buffer.from(JSON.stringify(integrationPayload))]])],
        [502,coverageFixtureZip([['release-delta.json',Buffer.from(JSON.stringify(retainedDelta))],['basic-report.json',report]])],
    ]);
    predecessor.integration.artifact_sha256=checksum(archives.get(501));
    predecessor.delta.artifact_sha256=checksum(archives.get(502));
    const currentMain='7'.repeat(40),nextSource='d'.repeat(40),nextOrigin='c'.repeat(40);
    const nextBranch='release/v_1.2.4';
    const previousPr={number:91,user:{login:'github-actions[bot]'},state:'closed',merged:true,merged_at:'2026-10-02T00:00:00Z',
        created_at:'2026-10-01T00:00:00Z',merge_commit_sha:currentMain,merged_by:{login:'github-actions[bot]'},
        head:{sha:candidate,ref:predecessor.branch,repo:{full_name:record.repository}},
        base:{ref:'main',sha:record.initial_main_sha,repo:{full_name:record.repository}}};
    const releaseChecks=['Release qualification','Complete required CI matrix'].map((name,index)=>({id:1000+index,name,
        app:{id:15368},head_sha:candidate,status:'completed',conclusion:'success',external_id:`release:123:1:${candidate}`}));
    const state={publication:null,artifactPatch:null,corrupt:false,redOwner:false,head:nextSource,downloads:[],writes:[]};
    const call=(path,method='GET',payload=null)=>{
        if (method!=='GET') state.writes.push({path,method,payload});
        if (path.endsWith('/collaborators/owner/permission')) return {permission:'admin'};
        if (path.endsWith('/git/matching-refs/tags/v_1.2.4')) return [];
        if (path.endsWith('/git/matching-refs/heads/'+nextBranch))
            return [{ref:'refs/heads/'+nextBranch,object:{type:'commit',sha:state.head}}];
        if (path.endsWith('/git/ref/heads/'+nextBranch)) return {object:{type:'commit',sha:state.head}};
        if (path.endsWith('/git/ref/heads/develop')) return {object:{type:'commit',sha:nextSource}};
        if (path.endsWith('/git/ref/tags/v_1.2.3')) return {object:{type:'commit',sha:currentMain}};
        if (path.endsWith('/releases/tags/v_1.2.3')) {
            if (state.publication) return state.publication;
            throw new Error('BLOCKED_API_NOT_FOUND: predecessor GitHub Release is not published yet.');
        }
        if (path.endsWith('/commits/'+currentMain)) return {sha:currentMain,commit:{tree:{sha:'3'.repeat(40)}},
            parents:[{sha:record.initial_main_sha},{sha:candidate}]};
        if (path.endsWith('/commits/'+currentMain+'/pulls?per_page=100')) return [previousPr];
        if (path.endsWith('/pulls/91')) return previousPr;
        if (path.endsWith('/pulls/91/reviews?per_page=100')) return [{id:19,user:{login:'owner'},state:'APPROVED',
            commit_id:candidate,submitted_at:'2026-10-01T12:00:00Z'}];
        if (path.endsWith('/actions/runs/123')) return {id:123,...run};
        if (path.endsWith(`/commits/${candidate}/check-runs?per_page=100&filter=all`))
            return {total_count:releaseChecks.length,check_runs:releaseChecks};
        const artifact=/\/actions\/artifacts\/(501|502)$/.exec(path);
        if (artifact) {
            const id=Number(artifact[1]),bytes=archives.get(id);
            return {id,name:id===501?'develop-integration-record':'release-delta-evidence',expired:false,
                digest:checksum(bytes),size_in_bytes:bytes.length,workflow_run:{id:id===501?456:123},...state.artifactPatch};
        }
        if (path.endsWith('/commits/'+nextSource)) return {sha:nextSource,tree:{sha:'2'.repeat(40)}};
        if (path.endsWith('/git/commits/'+nextSource)) return {sha:nextSource,tree:{sha:'2'.repeat(40)}};
        if (path.includes('/compare/') && (path.endsWith(candidate+'...'+nextSource)||path.endsWith(nextSource+'...'+nextSource)))
            return {behind_by:0};
        if (path.endsWith('/git/blobs') && method==='POST') return {sha:gitBlobSha(Buffer.from(payload.content))};
        if (path.endsWith('/git/trees') && method==='POST') return {sha:'1'.repeat(40)};
        if (path.endsWith('/git/commits') && method==='POST') return {sha:nextOrigin,parents:[{sha:nextSource}]};
        return schema3Call(path);
    };
    const transport=(executable,args)=>{
        if (executable==='gh') {
            if (args[1].includes('matching-refs/tags/v_')) return JSON.stringify([[{ref:'refs/tags/v_1.2.3'}]]);
            assert.match(args[1],/actions\/runs\/456\/attempts\/1\/jobs/);
            const jobs=schema3Adapters.integrationJobs.map(job=>state.redOwner&&job.name.endsWith('Required Chromium fixtures')
                ? {...job,conclusion:'failure'} : job);
            return JSON.stringify([{total_count:jobs.length,jobs}]);
        }
        assert.equal(executable,'git');
        if (args[0]==='fetch'||args[0]==='ls-tree') return '';
        if (args[0]==='rev-parse') return currentMain;
        if (args[0]==='push') {state.head=nextOrigin;return '';}
        assert.fail('Unexpected successor origin command '+args.join(' '));
    };
    const adapters={predecessorRecord:predecessor,predecessorJobs:basicJobs,git:transport,
        predecessorArtifacts:{download:(repository,id)=>{
            assert.equal(repository,record.repository);state.downloads.push(id);
            const bytes=Buffer.from(archives.get(id));if (state.corrupt) bytes[30]^=1;return bytes;
        }}};
    assert.equal(verifyPredecessor(call,record.repository,'v_1.2.3',adapters).previous_release_candidate_sha,candidate);
    assert.deepEqual(state.downloads,[501,502],'Both original predecessor archives are authenticated.');
    state.publication={tag_name:'v_1.2.3',draft:true,prerelease:false,assets:[]};
    assert.equal(verifyPredecessor(call,record.repository,'v_1.2.3',adapters).previous_stable_sha,currentMain);
    state.publication=null;
    for (const patch of [{expired:true},{workflow_run:{id:999}},{digest:'sha256:'+'0'.repeat(64)}]) {
        state.artifactPatch=patch;
        assert.throws(()=>verifyPredecessor(call,record.repository,'v_1.2.3',adapters),/BLOCKED/);
    }
    state.artifactPatch=null;state.corrupt=true;
    assert.throws(()=>verifyPredecessor(call,record.repository,'v_1.2.3',adapters),/digest/);
    state.corrupt=false;state.redOwner=true;
    assert.throws(()=>verifyPredecessor(call,record.repository,'v_1.2.3',adapters),/native owners/);
    state.redOwner=false;
    assert.throws(()=>verifyPredecessor(call,record.repository,'v_1.2.3',{
        ...adapters,predecessorArtifacts:{download:()=>{throw new Error('BLOCKED missing predecessor archive.');}}}),/missing predecessor archive/);
    assert.equal(state.writes.length,0,'Invalid predecessor evidence performs no writes.');
    const initialized=startNewRelease({version:'1.2.4',selected:nextSource},record.repository,{...adapters,api:call,
        context:{GITHUB_EVENT_NAME:'push',GITHUB_REF:'refs/heads/'+nextBranch,GITHUB_SHA:nextSource,GITHUB_ACTOR:'owner'}});
    assert.equal(initialized.state,'INITIALIZED');
    assert.equal(initialized.origin_sha,nextOrigin);
    assert.equal(initialized.selected_develop_sha,nextSource);
    assert.equal(initialized.previous_stable_sha,currentMain);
    assert.deepEqual(state.writes.find(write=>write.path.endsWith('/git/commits')).payload.parents,[nextSource]);
}
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
{
    const assembled={...record,lifecycle_schema:4,coverage_schema:2,source_qualification:'protected-develop-v1',
        initial_main_sha:record.previous_stable_sha,installer_required:false,delta:null,
        integration:{candidate_sha:record.selected_develop_sha,candidate_tree:'b'.repeat(40),trust:'protected-develop'}};
    const assemblyJobs=['Select release source','Prepare release candidate','Release qualification gate',
        'Prepare manual release assets','Prepare Windows companion installer','Open qualified release PR'].map((name,index)=>
        ({id:9000+index,name,status:'completed',conclusion:name==='Prepare Windows companion installer'?'skipped':'success',
            workflow_name:'Release qualification',run_id:Number(request.runId),run_attempt:run.run_attempt}));
    const noHistoricalReads={api:()=>{throw new Error('Release must not reopen historical qualification.');}};
    assert.deepEqual(verifyQualification(request,run,assemblyJobs,assembled,record.repository,candidate,noHistoricalReads),[]);
    assert.deepEqual(verifyQualification(request,{...run,event:'workflow_dispatch'},assemblyJobs,assembled,
        record.repository,candidate,noHistoricalReads),[],
        'Resumed editorial assembly retains the exact source and native assembly owners.');
    assert.throws(()=>verifyQualification(request,{...run,event:'pull_request'},assemblyJobs,assembled,
        record.repository,candidate,noHistoricalReads),/BLOCKED/,
        'Foreign event types cannot supply release assembly evidence.');
    assert.throws(()=>verifyQualification(request,run,assemblyJobs.map(job=>job.name==='Prepare manual release assets'
        ?{...job,conclusion:'failure'}:job),assembled,record.repository,candidate,noHistoricalReads),/BLOCKED/,
        'qualified develop does not excuse an incomplete release build');
}
const bootstrapWorkflow=read('.github/workflows/release-bootstrap.yml');
assert.match(qualification,/name: Select release source/);
assert.ok(!qualification.includes('resolveIntegrationBootstrap'),'ordinary release never bootstraps test authority');
assert.match(bootstrapWorkflow,/integration_recovery:JSON\.parse\(e\.INTEGRATION_RECOVERY \|\| 'null'\)/);
// Existing schema-2 consumers still require every native FULL/release owner for a repair release.
const recoveryRecord={...schema2Record,integration_recovery:{reason:'upload-artifact-digest-prefix'}};
assert.deepEqual(verifyQualification(request,run,schema2Jobs,recoveryRecord,record.repository,candidate),[]);
assert.throws(()=>verifyQualification(request,run,schema2Jobs.map(job=>job.name==='Required Chromium fixtures'
    ? {...job,conclusion:'skipped'} : job),recoveryRecord,record.repository,candidate),/BLOCKED/);
const completion=read('.github/workflows/release-promotion.yml');
assert.match(qualification,/Initialize or reuse immutable origin after release push/);
assert.doesNotMatch(qualification,/initialization_run:/);
assert.match(qualification,/workflow_dispatch:[\s\S]*external_notes:/);
assert.ok(qualification.includes("needs.prepare.outputs.notes_pending == 'true'"),
    'Pending external editorial handoff cannot publish successful qualification.');
assert.match(qualification,/name: Select release source[\s\S]*Freeze the observed main Git base/);
assert.doesNotMatch(qualification,/name: Run first-install full release lifecycle|uses: \.\/\.github\/workflows\/release-bootstrap\.yml|audit_profile:|php scripts\/audit\.php/);
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
assert.doesNotMatch(completion,/pull_request_review:/,'Only the post-assembly observer waits for review; early approval cannot start an unqualified duplicate.');
assert.match(completion,/types: \[closed\]/,'Closed-PR recovery remains available.');
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
{
    const f=maintenanceBridgeFixture(),repository='klusik/PHP_gallery';
    const predecessor=verifyPredecessor(f.api,repository,'v_0.132.8',f);
    const bridgeRecord={...f.record,...predecessor};
    const request={runId:'1',candidate:f.record.selected_develop_sha,branch:f.record.release_branch,
        mode:'complete',override:false,reason:'',acceptedFailures:[],manualReview:''};
    assert.equal(requireCurrentMainBase(request,repository,{...f,record:bridgeRecord}),f.record.initial_main_sha,
        'promotion leases actual reviewed maintenance main while preserving the real previous stable tag');
    assert.notEqual(bridgeRecord.initial_main_sha,bridgeRecord.previous_stable_sha);
    const foreignStable='c'.repeat(40);
    const foreignTag=path=>{
        if (path.endsWith('/git/ref/tags/v_0.132.7')) return {object:{type:'commit',sha:foreignStable}};
        if (path.endsWith('/commits/'+foreignStable)) return {sha:foreignStable,commit:{tree:{sha:bridgeRecord.previous_tree_sha}}};
        return f.api(path);
    };
    assert.throws(()=>requireCurrentMainBase(request,repository,{...f,api:foreignTag,
        record:{...bridgeRecord,previous_stable_tag:'v_0.132.7'}}),/stable tag moved/,
        'an arbitrary tag cannot replace the independently selected stable commit');
}
process.stdout.write('PASS exact qualification identities, complete coverage, no overrides, active policy and manual publication contracts\n');
