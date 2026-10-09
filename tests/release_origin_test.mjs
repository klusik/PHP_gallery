/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/release_origin_test.mjs
 * Module Type: Regression Test
 * Purpose: Refuse unselected or rewritten release origin before source qualification.
 * Responsibilities:
 *   - Validate canonical, monotonic origin record syntax
 *   - Verify the origin file first appears immediately after the selected develop commit
 *   - Reject amended origin bytes and incompatible published-tag/main/develop ancestry
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {originPath,validateOriginRecord,verifyReleaseOrigin,verifyPredecessor,peelReleaseTag} from '../.github/scripts/release-origin.mjs';

const stable='a'.repeat(40);
const selected='b'.repeat(40);
const initialized='c'.repeat(40);
const candidate='d'.repeat(40);
const currentDevelop='e'.repeat(40);
const previousCandidate='1'.repeat(40);
const previousTree='2'.repeat(40);
const digest='f'.repeat(40);
const repo='fixture/repo';
const version='1.2.3';
const original={schema_version:1,version,release_branch:'release/v_1.2.3',
    selected_develop_sha:selected,initial_main_sha:stable,previous_stable_tag:'v_1.2.2',
    previous_stable_sha:stable};
assert.equal(originPath(version),'.github/release-origins/v_1.2.3.json');
assert.throws(()=>originPath('1.2.03'),/BLOCKED/);
assert.doesNotThrow(()=>validateOriginRecord(original,version));
for (const record of [
    {...original,version:'1.2.2'}, {...original,release_branch:'release/v_1.2.2'},
    {...original,previous_stable_sha:selected}, {...original,selected_develop_sha:'HEAD'},
    {...original,previous_stable_tag:'v_1.2.3'}, {...original,previous_stable_tag:'v_2.0'},
    {...original,previous_stable_tag:'heads/develop'}, {...original,unexpected:'x'},
    {...original,schema_version:2}
]) assert.throws(()=>validateOriginRecord(record,version),/BLOCKED/);

/** Create independent Git and GitHub state to exercise release origin tampering.
 * @returns {{state:object,api:function(string,string=,object=):object,git:function(string,string[]):string}} Hermetic mutable source and server adapters.
 */
function fixture() {
    const state={introductions:[initialized],parents:[initialized,selected],initialBlob:digest,
        checkedBlob:digest,tagSha:stable,mainSha:stable,developSha:currentDevelop,
        committedOrigin:{...original},ancestors:new Set([previousCandidate+'...'+selected,selected+'...'+currentDevelop,
            initialized+'...'+candidate])};
    const git=(executable,args)=>{
        assert.equal(executable,'git');
        if (args[0]==='log') return args.at(-1).includes('release-predecessors') ? initialized : state.introductions.join('\n');
        if (args[0]==='show') return JSON.stringify(args[1].includes('release-predecessors')
            ? verifyPredecessor(api,repo,original.previous_stable_tag,{evidence}) : state.committedOrigin);
        if (args[0]==='rev-list') return state.parents.join(' ');
        if (args[0]==='rev-parse') {
            if (args[1].includes('release-predecessors')) return digest;
            if (args[1]===initialized+':'+originPath(version)) return state.initialBlob;
            if (args[1]===candidate+':'+originPath(version)) return state.checkedBlob;
        }
        assert.fail('Unexpected Git fixture call: '+args.join(' '));
    };
    const evidence={repository:repo,version:'1.2.2',final_main_sha:stable,final_tree:previousTree,
        candidate_sha:previousCandidate,ready:true,automated_result:'success',override:false,run_id:'99'};
    const api=path=>{
        if (path.endsWith('/git/ref/tags/v_1.2.2'))
            return {object:{type:'commit',sha:state.tagSha}};
        if (path.endsWith('/releases/tags/v_1.2.2')) return {tag_name:'v_1.2.2',draft:false,prerelease:false,assets:[{name:'release-evidence.json'}]};
        if (path.endsWith('/commits/'+stable)) return {sha:stable,commit:{tree:{sha:previousTree}},parents:[{sha:selected},{sha:previousCandidate}]};
        if (path.endsWith('/commits/'+previousCandidate)) return {sha:previousCandidate,commit:{tree:{sha:previousTree}}};
        if (path.endsWith('/commits/'+currentDevelop)) return {sha:currentDevelop,commit:{tree:{sha:previousTree}}};
        if (path.endsWith('/git/ref/heads/main')) return {object:{sha:state.mainSha}};
        if (path.endsWith('/git/ref/heads/develop')) return {object:{sha:state.developSha}};
        if (path.includes('/compare/')) {
            const pair=path.split('/compare/')[1];
            return {behind_by:state.ancestors.has(pair)?0:1};
        }
        assert.fail('Unexpected GitHub fixture call: '+path);
    };
    return {state,git,api,evidence};
}
{
    const f=fixture();
    const attestation=verifyReleaseOrigin(original,version,candidate,repo,f);
    assert.equal(attestation.selected_develop_sha,selected);
    assert.equal(attestation.initial_main_sha,stable);
    assert.equal(attestation.origin_commit_sha,initialized);
    assert.equal(attestation.previous_stable_tag,'v_1.2.2');
    f.state.introductions=[initialized,candidate];
    assert.throws(()=>verifyReleaseOrigin(original,version,candidate,repo,f),/unique introduction/);
    f.state.introductions=[initialized];
    f.state.parents=[initialized,currentDevelop];
    assert.throws(()=>verifyReleaseOrigin(original,version,candidate,repo,f),/immediately from selected/);
    f.state.parents=[initialized,selected];
    f.state.checkedBlob=selected;
    assert.throws(()=>verifyReleaseOrigin(original,version,candidate,repo,f),/was changed/);
    f.state.checkedBlob=digest;
    f.state.committedOrigin={...original,initial_main_sha:currentDevelop,previous_stable_sha:currentDevelop};
    assert.throws(()=>verifyReleaseOrigin(original,version,candidate,repo,f),/not the immutable committed/);
    f.state.committedOrigin={...original};
    f.state.tagSha=currentDevelop;
    // A moved tag is rejected against permanent publication evidence before lineage inspection.
    assert.throws(()=>verifyReleaseOrigin(original,version,candidate,repo,f),/predecessor permanent qualification evidence is inconsistent/);
    f.state.tagSha=stable;
    f.state.developSha=candidate;
    assert.throws(()=>verifyReleaseOrigin(original,version,candidate,repo,f),/ancestry/);
    f.state.developSha=currentDevelop;
    f.state.mainSha=currentDevelop;
    assert.throws(()=>verifyReleaseOrigin(original,version,candidate,repo,f),/current main/);
    f.state.mainSha=stable;
    f.state.ancestors.delete(initialized+'...'+candidate);
    assert.throws(()=>verifyReleaseOrigin(original,version,candidate,repo,f),/ancestry/);
    f.state.ancestors.add(initialized+'...'+candidate);
    assert.throws(()=>verifyReleaseOrigin(original,version,'HEAD',repo,f),/BLOCKED/);
}
process.stdout.write('PASS explicit release origin monotonicity, blob immutability and ancestry\n');

// Peel the actual annotated predecessor; legacy evidence is explicitly not a fabricated qualification.
{
    const legacy=JSON.parse((await import('node:fs')).readFileSync(new URL('../.github/release-legacy/v_0.125.json',import.meta.url),'utf8'));
    const call=path=>{
        if (path.includes('/git/ref/tags/')) return {object:{type:'tag',sha:legacy.tag_object_sha}};
        if (path.includes('/git/tags/')) return {sha:legacy.tag_object_sha,object:{type:'commit',sha:legacy.published_commit_sha}};
        if (path.endsWith('/commits/'+legacy.published_commit_sha)) return {sha:legacy.published_commit_sha,
            commit:{tree:{sha:legacy.tree_sha}},parents:[{sha:legacy.first_parent_sha},{sha:legacy.release_candidate_sha}]};
        if (path.endsWith('/commits/'+legacy.release_candidate_sha)) return {sha:legacy.release_candidate_sha,commit:{tree:{sha:legacy.tree_sha}}};
        if (path.includes('/releases/tags/')) return {tag_name:legacy.tag,draft:false,prerelease:false,assets:[]};
        assert.fail('Unexpected legacy call '+path);
    };
    assert.equal(peelReleaseTag(call,legacy.repository,legacy.tag).tag_object_sha,legacy.tag_object_sha);
    const verified=verifyPredecessor(call,legacy.repository,legacy.tag,{legacy});
    assert.equal(verified.previous_release_candidate_sha,legacy.release_candidate_sha);
    assert.equal(verified.predecessor_kind,'legacy-v_0.125');
    assert.throws(()=>verifyPredecessor(call,legacy.repository,legacy.tag,{legacy:{...legacy,tag_object_sha:'f'.repeat(40)}}),/legacy bootstrap/);
    assert.throws(()=>peelReleaseTag(path=>path.includes('/git/ref/') ? {object:{type:'tag',sha:legacy.tag_object_sha}} :
        {sha:legacy.tag_object_sha,object:{type:'tag',sha:legacy.tag_object_sha}},legacy.repository,legacy.tag),/cyclic/);
}
