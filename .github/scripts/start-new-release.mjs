/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/start-new-release.mjs
 * Module Type: Pushed Release Initialization
 * Purpose: Add an immutable origin to an existing maintainer-pushed release branch.
 * Responsibilities:
 *   - Preserve existing origins and bind new origins directly to the pushed develop commit
 *   - Lease the exact release head and never write main, develop or tags
 *   - Continue preparation in the same run without relying on token-created events
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {api,command,output} from './release-promotion.mjs';
import {originPath,validateOriginRecord,verifyPredecessor,verifyPredecessorLineage,verifyReleaseOrigin} from './release-origin.mjs';
import {isAncestor,matchingBranchSha} from './release-reconciliation.mjs';

/** Recursive JSON transport values narrowed by the origin and predecessor validators.
 * @typedef {import('./release-promotion.mjs').JsonValue} InitializationJson
 */

/** Initialize or reuse the origin of the exact pushed release branch.
 * @param {{version:string,selected:string}} request Branch-derived version and exact event commit.
 * @param {string} repository Exact owner/repository identity.
 * @param {{api?:function(string,string=,(InitializationJson|null)=):InitializationJson,git?:function(string,string[],(string|null)=):string,context?:Record<string,string|undefined>,evidence?:import('./release-promotion.mjs').PublicationEvidence,predecessorRecord?:import('./release-promotion.mjs').ReleaseRecord,predecessorJobs?:import('./release-promotion.mjs').ReleaseJob[]}} adapters Optional isolated API, Git, event and fully bound predecessor readers.
 * @returns {{state:string,branch:string,origin_sha:string,candidate_sha:string,selected_develop_sha:string,previous_stable_sha:string}} Immutable origin and current preparation checkout.
 */
export function startNewRelease(request,repository,adapters={}) {
    const call=adapters.api ?? api;
    const git=adapters.git ?? command;
    const context=adapters.context ?? process.env;
    const path=originPath(request.version);
    const branch='release/v_'+request.version;
    if (!/^[\w.-]+\/[\w.-]+$/.test(repository) || !/^[a-f0-9]{40}$/.test(request.selected)
        || context.GITHUB_EVENT_NAME!=='push' || context.GITHUB_REF!=='refs/heads/'+branch
        || context.GITHUB_SHA!==request.selected) throw new Error('BLOCKED exact release push identity required.');
    const permission=call('repos/'+repository+'/collaborators/'+encodeURIComponent(context.GITHUB_ACTOR ?? '')+'/permission');
    if (!['admin','maintain'].includes(permission?.permission)) throw new Error('BLOCKED release push requires a maintainer.');
    const absent=()=>{
        const tags=call('repos/'+repository+'/git/matching-refs/tags/v_'+request.version);
        if (!Array.isArray(tags)) throw new Error('BLOCKED target tag inventory unavailable.');
        return !tags.some(ref=>ref.ref==='refs/tags/v_'+request.version);
    };
    if (!absent()) throw new Error('BLOCKED immutable target tag already exists.');
    const head=matchingBranchSha(call,repository,branch);
    if (!head) throw new Error('BLOCKED pushed release branch is missing.');
    git('git',['fetch','origin','refs/heads/'+branch]);
    if (git('git',['ls-tree',head,'--',path])) {
        const stored=JSON.parse(git('git',['show',head+':'+path]));
        const checked=verifyReleaseOrigin(stored,request.version,head,repository,adapters);
        // A rerun may reuse O/Q after its original event D0; an unrelated writer is stale.
        if (head!==request.selected && request.selected!==stored.selected_develop_sha) {
            throw new Error('BLOCKED release branch advanced beyond this event.');
        }
        return {state:'REUSED_IMMUTABLE_ORIGIN',branch,origin_sha:checked.origin_commit_sha,candidate_sha:head,
            selected_develop_sha:stored.selected_develop_sha,previous_stable_sha:stored.previous_stable_sha};
    }
    if (head!==request.selected) throw new Error('BLOCKED release branch advanced before initialization.');
    const main=call('repos/'+repository+'/git/ref/heads/main').object?.sha;
    const develop=call('repos/'+repository+'/git/ref/heads/develop').object?.sha;
    // Select the stable tag on current main, never releases/latest or an ancestral git describe.
    const tags=JSON.parse(git('gh',['api','repos/'+repository+'/git/matching-refs/tags/v_','--paginate','--slurp'])).flat();
    const matching=tags.filter(ref=>/^refs\/tags\/v_(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?$/.test(ref.ref))
        .filter(ref=>git('git',['rev-parse',ref.ref+'^{commit}'])===main);
    if (matching.length!==1) throw new Error('BLOCKED current main must have one unambiguous stable tag.');
    const predecessor=verifyPredecessor(call,repository,matching[0].ref.slice('refs/tags/'.length),adapters);
    if (!isAncestor(call,repository,request.selected,develop)) throw new Error('BLOCKED pushed source is not retained on develop.');
    verifyPredecessorLineage(call,repository,predecessor,request.selected,adapters);
    const supplement='.github/release-predecessors/v_'+request.version+'.json';
    if (git('git',['ls-tree',request.selected,'--',path,supplement])) throw new Error('BLOCKED origin paths already exist.');
    const record={schema_version:1,version:request.version,release_branch:branch,selected_develop_sha:request.selected,
        initial_main_sha:main,previous_stable_tag:predecessor.previous_stable_tag,previous_stable_sha:main};
    validateOriginRecord(record,request.version);
    const source=call('repos/'+repository+'/git/commits/'+request.selected);
    if (source.sha!==request.selected || !/^[a-f0-9]{40}$/.test(source.tree?.sha ?? '')) throw new Error('BLOCKED source tree unavailable.');
    const entries=[];
    for (const [file,value] of [[path,record],[supplement,predecessor]]) {
        const blob=call('repos/'+repository+'/git/blobs','POST',{content:JSON.stringify(value,null,2)+'\n',encoding:'utf-8'});
        entries.push({path:file,mode:'100644',type:'blob',sha:blob.sha});
    }
    const tree=call('repos/'+repository+'/git/trees','POST',{base_tree:source.tree.sha,tree:entries});
    const commit=call('repos/'+repository+'/git/commits','POST',{message:'Initialize immutable origin for v_'+request.version+' (Refs #101, #133)',tree:tree.sha,parents:[request.selected]});
    if (!/^[a-f0-9]{40}$/.test(commit.sha ?? '') || commit.parents?.length!==1
        || commit.parents[0].sha!==request.selected) throw new Error('BLOCKED origin lost its exact single parent.');
    if (matchingBranchSha(call,repository,branch)!==head || !absent()
        || call('repos/'+repository+'/git/ref/heads/main').object?.sha!==main
        || call('repos/'+repository+'/git/ref/heads/develop').object?.sha!==develop
        || JSON.stringify(verifyPredecessor(call,repository,predecessor.previous_stable_tag,adapters))!==JSON.stringify(predecessor)) {
        throw new Error('BLOCKED initialization refs raced; created objects remain unattached.');
    }
    git('git',['fetch','origin',commit.sha]);
    // A normal push rejects non-ancestral concurrent writers; no force or protected ref is used.
    git('git',['push','origin',commit.sha+':refs/heads/'+branch]);
    if (matchingBranchSha(call,repository,branch)!==commit.sha) throw new Error('BLOCKED origin publication raced.');
    return {state:'INITIALIZED',branch,origin_sha:commit.sha,candidate_sha:commit.sha,
        selected_develop_sha:request.selected,previous_stable_sha:main};
}

/** Initialize the pushed branch and emit the checkout for the same preparation run.
 * @returns {void} Writes only the release origin commit and workflow output; never dispatches or publishes.
 */
export function main() {
    const version=(process.env.GITHUB_REF_NAME ?? '').slice('release/v_'.length);
    const result=startNewRelease({version,selected:process.env.GITHUB_SHA ?? ''},process.env.GITHUB_REPOSITORY ?? '');
    command('git',['checkout','--detach',result.candidate_sha]);
    output('GITHUB_OUTPUT','candidate_sha='+result.candidate_sha);
    output('GITHUB_STEP_SUMMARY',JSON.stringify(result,null,2));
}
if (process.argv[1] && import.meta.url===pathToFileURL(resolve(process.argv[1])).href) {
    try {main();} catch (error) {process.stderr.write(error.message+'\n');process.exitCode=1;}
}
