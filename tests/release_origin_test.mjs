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
import {originPath,validateOriginRecord,verifyReleaseOrigin} from '../.github/scripts/release-origin.mjs';

const stable='a'.repeat(40);
const selected='b'.repeat(40);
const initialized='c'.repeat(40);
const candidate='d'.repeat(40);
const currentDevelop='e'.repeat(40);
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
        ancestors:new Set([stable+'...'+selected,selected+'...'+currentDevelop,
            initialized+'...'+candidate])};
    const git=(executable,args)=>{
        assert.equal(executable,'git');
        if (args[0]==='log') return state.introductions.join('\n');
        if (args[0]==='rev-list') return state.parents.join(' ');
        if (args[0]==='rev-parse') {
            if (args[1]===initialized+':'+originPath(version)) return state.initialBlob;
            if (args[1]===candidate+':'+originPath(version)) return state.checkedBlob;
        }
        assert.fail('Unexpected Git fixture call: '+args.join(' '));
    };
    const api=path=>{
        if (path.endsWith('/git/ref/tags/v_1.2.2'))
            return {object:{type:'commit',sha:state.tagSha}};
        if (path.endsWith('/git/ref/heads/main')) return {object:{sha:state.mainSha}};
        if (path.endsWith('/git/ref/heads/develop')) return {object:{sha:state.developSha}};
        if (path.includes('/compare/')) {
            const pair=path.split('/compare/')[1];
            return {behind_by:state.ancestors.has(pair)?0:1};
        }
        assert.fail('Unexpected GitHub fixture call: '+path);
    };
    return {state,git,api};
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
    f.state.tagSha=currentDevelop;
    assert.throws(()=>verifyReleaseOrigin(original,version,candidate,repo,f),/previous stable tag moved/);
    f.state.tagSha=stable;
    f.state.developSha=candidate;
    assert.throws(()=>verifyReleaseOrigin(original,version,candidate,repo,f),/ancestry/);
    f.state.developSha=currentDevelop;
    f.state.mainSha=currentDevelop;
    assert.throws(()=>verifyReleaseOrigin(original,version,candidate,repo,f),/ancestry/);
    f.state.mainSha=stable;
    f.state.ancestors.delete(initialized+'...'+candidate);
    assert.throws(()=>verifyReleaseOrigin(original,version,candidate,repo,f),/ancestry/);
    f.state.ancestors.add(initialized+'...'+candidate);
    assert.throws(()=>verifyReleaseOrigin(original,version,'HEAD',repo,f),/BLOCKED/);
}
process.stdout.write('PASS explicit release origin monotonicity, blob immutability and ancestry\n');
