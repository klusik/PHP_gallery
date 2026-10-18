/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-draft-retirement.mjs
 * Module Type: Automatic Ready Draft Branch Retirement
 * Purpose: Remove the exact release ref after retained, fully verified draft completion.
 * Responsibilities:
 *   - Authenticate assembly, human review, immutable merge/tag and develop ancestry
 *   - Recheck the retained complete asset inventory and active branch dependencies
 *   - Delete only unchanged Q with a server lease and reconcile lost responses
 *   - Support cleanup retries from immutable records without a release branch
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {api,command,output} from './release-promotion.mjs';
import {peelReleaseTag} from './release-origin.mjs';
import {isAncestor,matchingBranchSha,verifyAutomaticCompletion} from './release-reconciliation.mjs';
import {hasActiveReleaseWork,validateRetirementRequest} from './release-retirement.mjs';

/** Current same-run cleanup identity, independently read back from GitHub.
 * @typedef {{GITHUB_RUN_ID:string,GITHUB_RUN_ATTEMPT:string,GITHUB_SHA:string,GITHUB_EVENT_NAME:string}} CleanupContext
 */
/** Immutable qualification and transport readers; no test adapter grants production authority.
 * @typedef {import('./release-reconciliation.mjs').AutomaticCompletionReaders & {api?:import('./release-completion.mjs').CompletionApi,git?:import('./release-completion.mjs').CompletionCommand}} CleanupAdapters
 */
/** Retained complete byte inventory for a single ready release draft.
 * @typedef {import('./release-completion.mjs').CompletionResult} DraftCompletion
 */

/** Bound the final independent draft asset inventory.
 * Type: number. Units: assets per API page. Scope: ready draft retirement.
 * Consumers: verifyReadyDraftRetirement.
 * Rationale: GitHub's maximum page size of one hundred contains all sixteen
 * owned files; a full page refuses cleanup because dependencies may be hidden.
 */
const DRAFT_ASSET_PAGE_SIZE=100;

/** Verify a ready draft and its permanent ancestry without needing the mutable release branch.
 * @param {DraftCompletion} result Original completion artifact from this workflow run.
 * @param {string} repository Expected owner/repository.
 * @param {CleanupContext} context Current cleanup run, SHA, event and attempt.
 * @param {CleanupAdapters} adapters Optional isolated provider and authenticated qualification readers.
 * @returns {void} Throws before deletion for missing, changed or incomplete release proof.
 */
export function verifyReadyDraftRetirement(result,repository,context,adapters={}) {
    const call=adapters.api ?? api;
    const evidence=result.evidence;
    validateRetirementRequest({branch:evidence?.branch,mode:'plan',manualReview:''});
    const sync=evidence?.develop_synchronization;
    if (result.draft_status!=='DRAFT_READY' || result.state!=='TAGGED'
        || evidence.repository!==repository || evidence.lifecycle_schema!==4
        || result.candidate_sha!==evidence.candidate_sha
        || result.main_sha!==evidence.final_main_sha || result.tag!==evidence.tag
        || result.tag!=='v_'+evidence.version
        || result.synchronization!=='RECONCILED_FF'
        || sync?.state!=='RECONCILED_FF' || sync.result_sha!==result.main_sha
        || sync.result_tree!==evidence.final_tree
        || sync.develop_base_sha!==evidence.selected_develop_sha
        || !Number.isSafeInteger(result.draft_release_id) || result.draft_release_id<1) {
        throw new Error('BLOCKED cleanup requires a retained synchronized schema-4 DRAFT_READY receipt.');
    }
    const run=call('repos/'+repository+'/actions/runs/'+context.GITHUB_RUN_ID);
    if (!/^[1-9]\d*$/.test(context.GITHUB_RUN_ID)
        || String(run.id)!==context.GITHUB_RUN_ID
        || String(run.run_attempt)!==context.GITHUB_RUN_ATTEMPT
        || run.repository?.full_name!==repository
        || run.path!=='.github/workflows/release-promotion.yml'
        || run.event!==context.GITHUB_EVENT_NAME || run.status!=='in_progress'
        || run.head_sha!==context.GITHUB_SHA
        || !(run.event==='workflow_dispatch' && run.head_branch===evidence.branch
            && run.head_sha===result.candidate_sha
            || run.event==='pull_request' && run.head_sha===result.main_sha)) {
        throw new Error('BLOCKED cleanup is not the same exact release completion run.');
    }
    verifyAutomaticCompletion(evidence,repository,call,adapters);
    const tag=peelReleaseTag(call,repository,result.tag);
    const merge=call('repos/'+repository+'/commits/'+result.main_sha);
    const candidate=call('repos/'+repository+'/commits/'+result.candidate_sha);
    if (tag.commit_sha!==result.main_sha || tag.tree_sha!==evidence.final_tree
        || tag.tag_object_sha!==evidence.tag_object_sha
        || tag.tag_object_type!==evidence.tag_object_type
        || merge.parents?.length!==2
        || merge.parents[0].sha!==evidence.initial_main_sha
        || merge.parents[1].sha!==result.candidate_sha
        || merge.commit?.tree?.sha!==evidence.final_tree
        || candidate.commit?.tree?.sha!==evidence.final_tree
        || !isAncestor(call,repository,evidence.selected_develop_sha,result.main_sha)
        || !isAncestor(call,repository,result.main_sha,
            call('repos/'+repository+'/git/ref/heads/main').object?.sha)
        || !isAncestor(call,repository,result.main_sha,
            call('repos/'+repository+'/git/ref/heads/develop').object?.sha)) {
        throw new Error('BLOCKED cleanup merge/tag/tree or permanent branch ancestry differs.');
    }
    const endpoint='repos/'+repository+'/releases/'+result.draft_release_id;
    const release=call(endpoint);
    // A draft has exactly sixteen assembly-owned attachments, including one
    // versioned installer. This structural count matches fillDraftAssets.
    const assets=result.draft_assets;
    const required=['production.zip','core-manifest.json','production-files.json',
        'release-metadata.json','release-notes.md','qualification-evidence.zip',
        'SHA256SUMS','final-integrity.json','PHP_Gallery_Manual.pdf',
        'PHP_Gallery_Manual_CZ.pdf','PHP_Gallery_Manual_DE.pdf',
        'PHP_Gallery_Manual_SV.pdf','release-evidence.json',
        'release-evidence.sha256','winapp-update.json'];
    if (release.id!==result.draft_release_id || release.tag_name!==result.tag
        || release.prerelease!==false || release.draft!==true
        || !Array.isArray(assets) || assets.length!==16
        || new Set(assets.map(asset=>asset.name)).size!==assets.length
        || required.some(name=>!assets.some(asset=>asset.name===name))) {
        throw new Error('BLOCKED cleanup draft or complete retained inventory differs.');
    }
    // Sixteen owned files fit in one maximum-size API page. Refuse a full page
    // instead of accepting truncated evidence about conflicting extra assets.
    const rows=call(endpoint+'/assets?per_page='+DRAFT_ASSET_PAGE_SIZE);
    if (!Array.isArray(rows) || rows.length>=DRAFT_ASSET_PAGE_SIZE) {
        throw new Error('BLOCKED cleanup asset inventory is incomplete.');
    }
    for (const asset of assets) {
        const matches=rows.filter(row=>row.name===asset.name);
        if (!/^[A-Za-z0-9_.-]+$/.test(asset.name)
            || !Number.isSafeInteger(asset.size) || asset.size<1
            || !/^sha256:[a-f0-9]{64}$/.test(asset.digest)
            || matches.length!==1 || matches[0].state!=='uploaded'
            || matches[0].size!==asset.size || matches[0].digest!==asset.digest) {
            throw new Error('BLOCKED cleanup asset missing, conflicting or incomplete: '+asset.name);
        }
    }
    for (const query of ['head='+encodeURIComponent(repository.split('/')[0]+':'+evidence.branch),
        'base='+encodeURIComponent(evidence.branch)]) {
        const pulls=call('repos/'+repository+'/pulls?state=open&'+query+'&per_page=100');
        if (!Array.isArray(pulls) || pulls.length!==0) {
            throw new Error('BLOCKED cleanup has open release branch PR dependencies.');
        }
    }
    if (hasActiveReleaseWork(call,repository,evidence.branch,context.GITHUB_RUN_ID)) {
        throw new Error('BLOCKED another active release operation still needs the branch.');
    }
}

/** Retire only the unchanged ready draft ref, observing unknown delete outcomes before retry.
 * @param {DraftCompletion} result Retained exact completion and asset inventory.
 * @param {string} repository Expected repository.
 * @param {CleanupContext} context Current same-run cleanup identity.
 * @param {CleanupAdapters} adapters Optional isolated provider and Git transports.
 * @returns {{state:string,detail:string}} RETIRED, ALREADY_DELETED or a safe SKIPPED state; unsafe proof or changed refs throw without deleting.
 */
export function retireReadyDraft(result,repository,context,adapters={}) {
    if (result.draft_status!=='DRAFT_READY' || result.synchronization!=='RECONCILED_FF'
        || result.evidence?.lifecycle_schema!==4) {
        return {state:'SKIPPED',detail:'Keep the branch until schema-4 draft completion and develop synchronization succeed.'};
    }
    const call=adapters.api ?? api,git=adapters.git ?? command;
    verifyReadyDraftRetirement(result,repository,context,adapters);
    const branch=result.evidence.branch;
    const head=matchingBranchSha(call,repository,branch);
    if (head===null) return {state:'ALREADY_DELETED',detail:'Verified immutable completion remains available without the release ref.'};
    if (head!==result.candidate_sha) throw new Error('BLOCKED release branch moved after draft completion.');
    git('gh',['auth','setup-git']);
    // Repeat mutable proof immediately before the server-side compare-and-swap.
    verifyReadyDraftRetirement(result,repository,context,adapters);
    let failure;
    try {
        git('git',['push','--force-with-lease=refs/heads/'+branch+':'+head,
            'origin',':refs/heads/'+branch]);
    } catch (error) {failure=error;}
    if (matchingBranchSha(call,repository,branch)!==null) {
        throw new Error('BLOCKED release branch deletion was not confirmed; retain and retry only cleanup.',{cause:failure});
    }
    return {state:'RETIRED',detail:'Exact Q ref removed after verified draft, retained evidence and permanent main/develop ancestry.'};
}

/** Read this run's retained completion; report cleanup independently of manual publication.
 * @returns {void} Emits cleanup outcome; failure preserves the original draft and evidence.
 */
export function main() {
    const result=JSON.parse(readFileSync(resolve(process.env.RELEASE_COMPLETION_FILE),'utf8'));
    const repository=process.env.GITHUB_REPOSITORY ?? '';
    const outcome=retireReadyDraft(result,repository,process.env);
    output('GITHUB_STEP_SUMMARY','Release branch cleanup: '+outcome.state+'\n\n'+outcome.detail);
}
if (process.argv[1] && import.meta.url===pathToFileURL(resolve(process.argv[1])).href) {
    try {main();} catch (error) {
        output('GITHUB_STEP_SUMMARY','Release branch cleanup: BLOCKED\n\n'+error.message);
        process.stderr.write(error.message+'\n');process.exitCode=1;
    }
}
