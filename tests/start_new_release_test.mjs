/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/start_new_release_test.mjs
 * Module Type: Regression Test
 * Purpose: Exercise owner-authorized atomic release initialization and safe qualification handoff.
 * Responsibilities:
 *   - Reject duplicate immutable tags and conflicting or raced branch creation
 *   - Preserve the first origin commit on retries, including selected develop identity
 *   - Refuse current v_0.126 reinitialization and unauthorized dispatches
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {requireInitializationHandoff} from '../.github/scripts/release-owner-authorization.mjs';
import {startNewRelease} from '../.github/scripts/start-new-release.mjs';

/** Build an isolated native-API origin creation fixture with no real repository writes.
 * @returns {{api:function(string,string=,object=):object,git:function(string,string[]):string,context:Record<string,string>,evidence:Record<string,string|boolean>,state:Record<string,unknown>}} Inert server and immutable-origin reader.
 */
function fixture() {
    const repo='fixture/gallery',main='a'.repeat(40),selected='b'.repeat(40),previousQ='c'.repeat(40),tree='d'.repeat(40),origin='e'.repeat(40);
    const evidence={repository:repo,version:'1.2',final_main_sha:main,final_tree:tree,candidate_sha:previousQ,
        ready:true,automated_result:'success',override:false,run_id:'1'};
    const record={schema_version:1,version:'1.3',release_branch:'release/v_1.3',selected_develop_sha:selected,
        initial_main_sha:main,previous_stable_tag:'v_1.2',previous_stable_sha:main};
    const predecessor={previous_stable_tag:'v_1.2',previous_tag_object_sha:main,previous_tag_object_type:'commit',
        previous_stable_sha:main,previous_release_candidate_sha:previousQ,previous_tree_sha:tree,predecessor_kind:'qualified'};
    const state={existing:null,tag:false,race:false,tagRace:false,tagAfterCreation:false,developRace:false,
        writes:[],origins:0,existingPaths:false,handoff:true};
    const api=(path,method='GET',payload=null)=>{
        if (method!=='GET') state.writes.push({path,method,payload});
        if (path.includes('/collaborators/fixture/permission')) return {permission:'admin'};
        if (path.includes('/deployment-branch-policies?')) return {total_count:1,branch_policies:[{name:'main',type:'branch'}]};
        if (path.endsWith('/environments/release-promotion')) return {protection_rules:[{type:'required_reviewers',prevent_self_review:false,
            reviewers:[{type:'User',reviewer:{login:'fixture'}}]}],can_admins_bypass:false,
            deployment_branch_policy:{custom_branch_policies:true,protected_branches:false}};
        if (path.includes('/git/matching-refs/tags/')) return state.tag || (state.tagRace && state.origins)
            || (state.tagAfterCreation && state.existing) ? [{ref:'refs/tags/v_1.3'}]:[];
        if (path.includes('/git/matching-refs/heads/')) return state.existing?[{ref:'refs/heads/release/v_1.3',object:{type:'commit',sha:state.existing}}]:[];
        if (path.endsWith('/releases/latest') || path.endsWith('/releases/tags/v_1.2')) return {tag_name:'v_1.2',draft:false,prerelease:false,assets:[{name:'release-evidence.json'}]};
        if (path.endsWith('/git/ref/tags/v_1.2')) return {object:{type:'commit',sha:main}};
        if (path.endsWith('/commits/'+main)) return {sha:main,commit:{tree:{sha:tree}},parents:[{sha:'0'.repeat(40)},{sha:previousQ}]};
        if (path.endsWith('/commits/'+previousQ)) return {sha:previousQ,commit:{tree:{sha:tree}}};
        if (path.endsWith('/git/ref/heads/main')) return {object:{sha:state.race && state.origins ? 'f'.repeat(40):main}};
        if (path.endsWith('/git/ref/heads/develop')) return {object:{sha:state.developRace && state.origins?'f'.repeat(40):selected}};
        if (path.includes('/compare/')) return {behind_by:path.endsWith(previousQ+'...'+selected) || path.endsWith(origin+'...'+origin)?0:1};
        if (path.endsWith('/git/commits/'+selected)) return {sha:selected,tree:{sha:tree}};
        if (path.endsWith('/git/blobs') || path.endsWith('/git/trees')) return {sha:tree};
        if (path.endsWith('/git/commits') && method==='POST') {state.origins++;assert.deepEqual(payload.parents,[selected]);return {sha:origin,parents:[{sha:selected}]};}
        if (path.endsWith('/git/refs') && method==='POST') {assert.equal(state.existing,null);state.existing=payload.sha;return {object:{sha:payload.sha}};}
        if (path.endsWith('/dispatches') && method==='POST') return {};
        assert.fail('Unexpected start-release API '+path);
    };
    const git=(executable,args)=>{
        assert.equal(executable,'git');
        if (args[0]==='fetch') return '';
        if (args[0]==='ls-tree') return state.existingPaths?'100644 blob '+tree+'\torigin.json\0':'';
        if (args[0]==='show' && args[1].endsWith('release-qualification.yml')) return state.handoff?'initialization_run:':'';
        if (args[0]==='show') return JSON.stringify(args[1].includes('release-predecessors')?predecessor:record);
        if (args[0]==='log') return origin;
        if (args[0]==='rev-list') return origin+' '+selected;
        if (args[0]==='rev-parse') return tree;
        assert.fail('Unexpected origin retry Git '+args.join(' '));
    };
    return {api,git,evidence,state,context:{GITHUB_SHA:main,GITHUB_EVENT_NAME:'workflow_dispatch',GITHUB_REF:'refs/heads/main',GITHUB_ACTOR:'fixture'}};
}
{
    const f=fixture();const first=startNewRelease({version:'1.3',selected:''},'fixture/gallery',f);
    assert.equal(first.state,'INITIALIZED');assert.equal(f.state.origins,1);
    assert.equal(f.state.writes.filter(write=>write.path.endsWith('/git/refs')).length,1);
    assert.equal(f.state.writes.filter(write=>write.path.endsWith('/git/blobs')).length,2);
    const reused=startNewRelease({version:'1.3',selected:''},'fixture/gallery',f);
    assert.equal(reused.state,'REUSED_IMMUTABLE_ORIGIN');assert.equal(reused.origin_sha,first.origin_sha);
    assert.equal(f.state.origins,1,'Retry cannot recreate or amend the immutable origin.');
    assert.throws(()=>startNewRelease({version:'1.3',selected:'f'.repeat(40)},'fixture/gallery',f),/different develop/);
    assert.ok(f.state.writes.every(write=>!write.path.includes('/heads/main') && !write.path.includes('/heads/develop')));
    assert.ok(f.state.writes.every(write=>write.method==='POST'),'No ref update/force route is used.');
}
{
    const f=fixture();f.state.tag=true;
    assert.throws(()=>startNewRelease({version:'1.3',selected:''},'fixture/gallery',f),/tag already exists/);
    assert.equal(f.state.writes.length,0);
    f.state.tag=false;f.state.race=true;
    assert.throws(()=>startNewRelease({version:'1.3',selected:''},'fixture/gallery',f),/refs raced/);
    assert.equal(f.state.existing,null,'Raced origin objects cannot publish a branch.');
}
for (const field of ['tagRace','developRace']) {
    const f=fixture();f.state[field]=true;
    assert.throws(()=>startNewRelease({version:'1.3',selected:''},'fixture/gallery',f),/refs raced/);
    assert.equal(f.state.existing,null,'A moved target tag or develop head blocks ref creation.');
    assert.ok(!f.state.writes.some(write=>write.path.endsWith('/dispatches')));
}
{
    const f=fixture();f.state.tagAfterCreation=true;
    assert.throws(()=>startNewRelease({version:'1.3',selected:''},'fixture/gallery',f),/handoff refs raced/);
    assert.equal(f.state.existing,'e'.repeat(40),'A created origin remains immutable for explicit recovery.');
    assert.ok(!f.state.writes.some(write=>write.path.endsWith('/dispatches')),'A raced context cannot trigger qualification.');
    assert.ok(f.state.writes.every(write=>write.method==='POST'),'Recovery never moves or deletes refs.');
}
{
    const f=fixture();f.context.GITHUB_ACTOR='other';
    assert.throws(()=>startNewRelease({version:'1.3',selected:''},'fixture/gallery',f),/owner dispatch/);
    assert.equal(f.state.writes.length,0);
}
{
    const f=fixture();f.state.existingPaths=true;
    assert.throws(()=>startNewRelease({version:'1.3',selected:''},'fixture/gallery',f),/already contains immutable/);
    assert.equal(f.state.writes.length,0);
    f.state.existingPaths=false;f.state.handoff=false;
    assert.throws(()=>startNewRelease({version:'1.3',selected:''},'fixture/gallery',f),/initialization qualification contract/);
    assert.equal(f.state.writes.length,0);
}
process.stdout.write('PASS hosted atomic origin, explicit selection, safe retry, duplicate tag and raced ref contracts\n');

{
    const record={branch:'release/v_1.3',origin_sha:'a'.repeat(40),selected_develop_sha:'b'.repeat(40),owner:'fixture',run_id:'700',run_attempt:'1'};
    const run={path:'.github/workflows/start-new-release.yml',event:'workflow_dispatch',head_branch:'main',
        actor:{login:'fixture'},run_attempt:1,status:'completed',conclusion:'success'};
    requireInitializationHandoff(()=>run,'fixture/gallery','700',record,record.branch,record.origin_sha);
    for (const patch of [{conclusion:'failure'},{event:'push'},{head_branch:'develop'},{run_attempt:2},
        {actor:{login:'other'}},{status:'in_progress'}]) {
        assert.throws(()=>requireInitializationHandoff(()=>({...run,...patch}),'fixture/gallery','700',record,record.branch,record.origin_sha),/BLOCKED/);
    }
    assert.throws(()=>requireInitializationHandoff(()=>run,'fixture/gallery','700',record,record.branch,'c'.repeat(40)),/unbound/);
}
