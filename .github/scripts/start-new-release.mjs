/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/start-new-release.mjs
 * Module Type: Hosted Release Initialization
 * Purpose: Atomically create a selected release branch with its immutable origin and predecessor evidence.
 * Responsibilities:
 *   - Require the owner dispatch and separately approved main-only environment
 *   - Reject duplicate tags, conflicting branches, moved predecessors and fabricated develop origins
 *   - Publish one single-parent origin commit through a create-only ref operation
 *   - Hand off to existing release qualification without promotion or publication
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {writeFileSync} from 'node:fs';
import {api,command,output} from './release-promotion.mjs';
import {originPath,validateOriginRecord,verifyPredecessor,verifyPredecessorLineage,verifyReleaseOrigin} from './release-origin.mjs';
import {isAncestor,matchingBranchSha} from './release-reconciliation.mjs';
import {requireOwnerDispatch,requireOwnerEnvironment} from './release-owner-authorization.mjs';

/** Create a release origin atomically or reuse an exactly verified existing initialization.
 * @param {{version:string,selected:string}} request Owner-selected version and optional develop SHA.
 * @param {string} repository Exact owner/repository.
 * @param {{api?:function(string,string=,object=):object,git?:function(string,string[]):string,context?:Record<string,string|undefined>,evidence?:Record<string,unknown>}} adapters Isolated transport/context fixtures.
 * @returns {{state:string,branch:string,origin_sha:string,selected_develop_sha:string,previous_stable_sha:string}} Audit-ready initialization and qualification handoff identity.
 */
export function startNewRelease(request,repository,adapters={}) {
    const call = adapters.api ?? api;
    const git = adapters.git ?? command;
    const context = adapters.context ?? process.env;
    requireOwnerDispatch(repository,call,context);
    requireOwnerEnvironment(call,repository,'release-promotion');
    const path = originPath(request.version);
    if (repository==='klusik/PHP_gallery' && request.version==='0.126') {
        throw new Error('BLOCKED v_0.126 already has a local immutable origin; owner must transfer it unchanged.');
    }
    const branch = 'release/v_'+request.version;
    const targetTagAbsent = ()=>{
        const tags = call('repos/'+repository+'/git/matching-refs/tags/v_'+request.version);
        return Array.isArray(tags) && !tags.some(ref=>ref.ref==='refs/tags/v_'+request.version);
    };
    if (!targetTagAbsent()) {
        throw new Error('BLOCKED immutable target tag already exists or tag inventory unavailable.');
    }
    const latest = call('repos/'+repository+'/releases/latest');
    const predecessor = verifyPredecessor(call,repository,latest.tag_name,adapters);
    const main = call('repos/'+repository+'/git/ref/heads/main').object?.sha;
    if (main!==predecessor.previous_stable_sha || context.GITHUB_SHA!==main) {
        throw new Error('BLOCKED current main/default workflow differs from the exact published predecessor.');
    }
    const develop = call('repos/'+repository+'/git/ref/heads/develop').object?.sha;
    const existing = matchingBranchSha(call,repository,branch);
    let selected = request.selected || develop;
    let originSha;
    if (existing) {
        git('git',['fetch','origin','refs/heads/'+branch]);
        const stored = JSON.parse(git('git',['show',existing+':'+path]));
        if (request.selected && request.selected!==stored.selected_develop_sha) {
            throw new Error('BLOCKED retry selected a different develop SHA.');
        }
        const checked = verifyReleaseOrigin(stored,request.version,existing,repository,adapters);
        selected = stored.selected_develop_sha;
        originSha = checked.origin_commit_sha;
    } else {
        if (!isAncestor(call,repository,selected,develop)) throw new Error('BLOCKED selected SHA is not retained on develop.');
        verifyPredecessorLineage(call,repository,predecessor,selected,adapters);
        const supplement='.github/release-predecessors/v_'+request.version+'.json';
        if (git('git',['ls-tree','-z',selected,'--',path,supplement])) {
            throw new Error('BLOCKED selected develop already contains immutable initialization paths.');
        }
        if (!git('git',['show',selected+':.github/workflows/release-qualification.yml']).includes('initialization_run:')) {
            throw new Error('BLOCKED selected develop lacks the owner initialization qualification contract.');
        }
        const record = {schema_version:1,version:request.version,release_branch:branch,
            selected_develop_sha:selected,initial_main_sha:main,previous_stable_tag:latest.tag_name,
            previous_stable_sha:predecessor.previous_stable_sha};
        validateOriginRecord(record,request.version);
        const source = call('repos/'+repository+'/git/commits/'+selected);
        if (source.sha!==selected || !/^[a-f0-9]{40}$/.test(source.tree?.sha ?? '')) {
            throw new Error('BLOCKED selected develop commit tree unavailable.');
        }
        const entries = [];
        for (const [file,value] of [[path,record],[supplement,predecessor]]) {
            const blob = call('repos/'+repository+'/git/blobs','POST',{content:JSON.stringify(value,null,2)+'\n',encoding:'utf-8'});
            entries.push({path:file,mode:'100644',type:'blob',sha:blob.sha});
        }
        const tree = call('repos/'+repository+'/git/trees','POST',{base_tree:source.tree.sha,tree:entries});
        const commit = call('repos/'+repository+'/git/commits','POST',{message:'Initialize immutable origin for v_'+request.version+' (refs #101)',
            tree:tree.sha,parents:[selected]});
        if (!/^[a-f0-9]{40}$/.test(commit.sha ?? '') || commit.parents?.length!==1 || commit.parents[0]?.sha!==selected) {
            throw new Error('BLOCKED origin creation did not preserve selected single parent.');
        }
        if (call('repos/'+repository+'/git/ref/heads/main').object?.sha!==main
            || call('repos/'+repository+'/git/ref/heads/develop').object?.sha!==develop
            || matchingBranchSha(call,repository,branch)
            || !targetTagAbsent()
            || JSON.stringify(verifyPredecessor(call,repository,latest.tag_name,adapters))!==JSON.stringify(predecessor)) {
            throw new Error('BLOCKED release initialization refs raced; created objects remain unattached.');
        }
        // Ref creation is atomic. A concurrent creator receives an error; never PATCH, force or repair the origin.
        call('repos/'+repository+'/git/refs','POST',{ref:'refs/heads/'+branch,sha:commit.sha});
        originSha = commit.sha;
    }
    // GitHub creates one ref atomically but cannot lease several refs in that operation.
    // Preserve any created origin and refuse handoff when a concurrent writer changes the context.
    if (!targetTagAbsent()
        || matchingBranchSha(call,repository,branch)!==(existing ?? originSha)
        || call('repos/'+repository+'/git/ref/heads/main').object?.sha!==main
        || call('repos/'+repository+'/git/ref/heads/develop').object?.sha!==develop
        || JSON.stringify(verifyPredecessor(call,repository,latest.tag_name,adapters))!==JSON.stringify(predecessor)) {
        throw new Error('BLOCKED qualification handoff refs raced; immutable origin '+originSha+' remains unchanged.');
    }
    call('repos/'+repository+'/actions/workflows/release-qualification.yml/dispatches','POST',
        {ref:branch,inputs:{version:request.version,initialization_run:context.GITHUB_RUN_ID ?? ''}});
    return {state:existing ? 'REUSED_IMMUTABLE_ORIGIN':'INITIALIZED',branch,origin_sha:originSha,
        selected_develop_sha:selected,previous_stable_sha:predecessor.previous_stable_sha};
}

/** Run owner-approved initialization and retain its exact identities in the hosted audit trail.
 * @returns {void} Creates only a release working ref and requests qualification; never publishes.
 */
export function main() {
    const result = startNewRelease({version:process.env.RELEASE_VERSION ?? '',selected:process.env.SELECTED_DEVELOP_SHA ?? ''},
        process.env.GITHUB_REPOSITORY ?? '');
    writeFileSync(resolve(process.env.RUNNER_TEMP,'release-initialization.json'),JSON.stringify({
        ...result,owner:process.env.GITHUB_ACTOR,run_id:process.env.GITHUB_RUN_ID,
        run_attempt:process.env.GITHUB_RUN_ATTEMPT},null,2)+'\n');
    output('GITHUB_STEP_SUMMARY',JSON.stringify(result,null,2));
}
if (process.argv[1] && import.meta.url===pathToFileURL(resolve(process.argv[1])).href) {
    try {main();} catch (error) {process.stderr.write(error.message+'\n');process.exitCode=1;}
}
