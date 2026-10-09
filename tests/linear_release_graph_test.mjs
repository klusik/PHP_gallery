/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/linear_release_graph_test.mjs
 * Module Type: Regression Test
 * Purpose: Exercise the two-release linear lifecycle against real isolated Git objects.
 * Responsibilities:
 *   - Prove annotated tag peeling and non-ancestral published-main release provenance
 *   - Verify full three-way content including parallel features, deletions and conflicts
 *   - Refuse wrong trees and extra parents without touching the source repository
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {mkdtempSync,writeFileSync,readFileSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {spawnSync} from 'node:child_process';
import {peelReleaseTag,verifyPredecessor,verifyReleaseOrigin} from '../.github/scripts/release-origin.mjs';
import {linearMergeTree,verifyLinearContent,verifyFastForwardHistory} from '../.github/scripts/release-reconciliation.mjs';

const directory=mkdtempSync(join(tmpdir(),'gallery-linear-graph-'));
const execute=(executable,args)=>{
    assert.equal(executable,'git');
    const child=spawnSync('git',['-C',directory,...args],{encoding:'utf8',timeout:10000});
    assert.ok(!child.error && (child.status===0 || (args[0]==='merge-tree' && child.status===1)),child.stderr);
    return args.includes('-z') || args[0]==='diff' ? child.stdout : child.stdout.trim();
};
const git=(...args)=>execute('git',args);
const save=(name,text)=>writeFileSync(join(directory,name),text);
const commit=message=>{git('add','-A');git('commit','-qm',message);return git('rev-parse','HEAD');};
const ancestor=(left,right)=>spawnSync('git',['-C',directory,'merge-base','--is-ancestor',left,right]).status===0;
try {
    git('init','-q');git('config','user.name','Fixture owner');git('config','user.email','fixture@example.test');
    save('source.txt','old\n');save('parallel.txt','original\n');save('delete.txt','remove me\n');
    const oldMain=commit('Initial published baseline');
    save('source.txt','previous release\n');
    const previousQ=commit('Previous qualified release');
    const previousTree=git('rev-parse',previousQ+'^{tree}');
    const previousM=git('commit-tree',previousTree,'-p',oldMain,'-p',previousQ,'-m','Publish first release');
    git('tag','-a','v_1.1','-m','First release',previousM);
    const tagObject=git('rev-parse','v_1.1');
    assert.notEqual(tagObject,previousM);
    git('switch','-qc','release-fixture',previousQ);
    const origin={schema_version:1,version:'1.2',release_branch:'release/v_1.2',selected_develop_sha:previousQ,
        initial_main_sha:previousM,previous_stable_tag:'v_1.1',previous_stable_sha:previousM};
    const predecessor={previous_stable_tag:'v_1.1',previous_tag_object_sha:tagObject,previous_tag_object_type:'tag',
        previous_stable_sha:previousM,previous_release_candidate_sha:previousQ,previous_tree_sha:previousTree,predecessor_kind:'qualified'};
    git('update-ref','refs/heads/main',previousM);git('update-ref','refs/heads/develop',previousQ);
    const fs=await import('node:fs');fs.mkdirSync(join(directory,'.github/release-origins'),{recursive:true});
    fs.mkdirSync(join(directory,'.github/release-predecessors'),{recursive:true});
    save('.github/release-origins/v_1.2.json',JSON.stringify(origin));
    save('.github/release-predecessors/v_1.2.json',JSON.stringify(predecessor));
    const initialized=commit('Immutable schema v1 origin');
    save('source.txt','next release\n');rmSync(join(directory,'delete.txt'));
    const releaseCandidate=commit('Stabilized second release');
    assert.equal(ancestor(previousM,releaseCandidate),false);
    assert.doesNotThrow(()=>verifyFastForwardHistory(previousQ,previousQ,releaseCandidate,execute));
    const nonlinearQ=git('commit-tree',git('rev-parse',releaseCandidate+'^{tree}'),'-p',releaseCandidate,'-p',previousM,
        '-m','Invalid linear develop destination');
    assert.throws(()=>verifyFastForwardHistory(previousQ,previousQ,nonlinearQ,execute),/without new merge commits/);
    const evidence={repository:'fixture/graph',version:'1.1',final_main_sha:previousM,final_tree:previousTree,
        candidate_sha:previousQ,ready:true,automated_result:'success',override:false,run_id:'1'};
    const api=path=>{
        if (path.includes('/git/ref/tags/')) return {object:{type:'tag',sha:tagObject}};
        if (path.includes('/git/tags/')) return {sha:tagObject,object:{type:'commit',sha:previousM}};
        if (path.includes('/commits/')) {
            const sha=path.split('/commits/')[1];
            return {sha,commit:{tree:{sha:git('rev-parse',sha+'^{tree}')}},
                parents:git('rev-list','--parents','-n','1',sha).split(' ').slice(1).map(sha=>({sha}))};
        }
        if (path.includes('/releases/tags/')) return {tag_name:'v_1.1',draft:false,prerelease:false,assets:[{name:'release-evidence.json'}]};
        if (path.endsWith('/git/ref/heads/main')) return {object:{sha:previousM}};
        if (path.endsWith('/git/ref/heads/develop')) return {object:{sha:previousQ}};
        if (path.includes('/compare/')) {const [left,right]=path.split('/compare/')[1].split('...');return {behind_by:ancestor(left,right)?0:1};}
        assert.fail('Unexpected graph API '+path);
    };
    assert.equal(peelReleaseTag(api,'fixture/graph','v_1.1').commit_sha,previousM);
    assert.deepEqual(verifyPredecessor(api,'fixture/graph','v_1.1',{evidence}),predecessor);
    const verified=verifyReleaseOrigin(origin,'1.2',releaseCandidate,'fixture/graph',{api,git:execute,evidence});
    assert.equal(verified.origin_commit_sha,initialized);
    assert.equal(verified.source_base,previousQ);
    assert.notEqual(verified.source_base,previousM);
    git('switch','-qc','parallel-fixture',previousQ);
    save('parallel.txt','parallel feature one\n');const first=commit('Parallel feature one');
    save('feature.txt','parallel feature two\n');const developHead=commit('Parallel feature two');
    const merge=linearMergeTree(previousQ,developHead,releaseCandidate,execute);
    assert.deepEqual(merge.conflicts,[]);
    const reconciledCommit=git('commit-tree',merge.tree,'-p',developHead,'-m','Equivalent reconciliation');
    const proof=verifyLinearContent(previousQ,developHead,releaseCandidate,reconciledCommit,execute);
    assert.equal(proof.result_tree,merge.tree);
    assert.equal(ancestor(first,reconciledCommit),true);
    assert.equal(git('show',reconciledCommit+':source.txt'),'next release');
    assert.equal(git('show',reconciledCommit+':feature.txt'),'parallel feature two');
    assert.equal(git('ls-tree',reconciledCommit,'--','delete.txt'),'','Released deletion must survive replay.');
    const lost=git('commit-tree',git('rev-parse',developHead+'^{tree}'),'-p',developHead,'-m','Lost release changes');
    assert.throws(()=>verifyLinearContent(previousQ,developHead,releaseCandidate,lost,execute),/lost released or parallel changes/);
    const wrongParents=git('commit-tree',merge.tree,'-p',developHead,'-p',releaseCandidate,'-m','Forbidden develop merge');
    assert.throws(()=>verifyLinearContent(previousQ,developHead,releaseCandidate,wrongParents,execute),/exactly the observed develop parent/);
    save('source.txt','parallel conflicting release edit\n');const conflicting=commit('Conflict requires owner decision');
    const conflict=linearMergeTree(previousQ,conflicting,releaseCandidate,execute);
    assert.ok(conflict.conflicts.includes('source.txt'));
    const unresolved=git('commit-tree',conflict.tree,'-p',conflicting,'-m','Unreviewed conflict');
    assert.throws(()=>verifyLinearContent(previousQ,conflicting,releaseCandidate,unresolved,execute),/explicit decisions/);
    // The real legacy record also binds the checked-in annotated tag object, not a made-up direct ref.
    const legacy=JSON.parse(readFileSync(new URL('../.github/release-legacy/v_0.125.json',import.meta.url),'utf8'));
    assert.equal(legacy.qualification,'UNKNOWN_LEGACY');
} finally {
    rmSync(directory,{recursive:true,force:true});
}
process.stdout.write('PASS real annotated two-release graph, immutable v1 origin, linear replay, retained features and conflicts\n');
