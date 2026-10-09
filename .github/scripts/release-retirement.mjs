/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-retirement.mjs
 * Module Type: Guarded Release Branch Retirement
 * Purpose: Retire only an explicitly selected, fully reconciled release ref with a server-side Git lease.
 * Responsibilities:
 *   - Keep cleanup planning read-only and require durable publication/review/CI proof
 *   - Block active PRs, queued/running release operations and moved branch refs
 *   - Require an independently reviewed environment for the one authorized ref deletion
 *   - Preserve original immutable tag, GitHub Release and historical audit records
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {api,command,output} from './release-promotion.mjs';
import {inspectSync,matchingBranchSha} from './release-reconciliation.mjs';
import {requireOwnerDispatch,requireOwnerEnvironment} from './release-owner-authorization.mjs';

/** @typedef {{branch:string,mode:string,manualReview:string}} RetirementRequest */
/** @typedef {function(string,string=,object=):object} GithubReader */
/** @typedef {function(string,string[]):string} GitCommand */

/** Validate targeted cleanup intent without wildcard branch operations.
 * @param {RetirementRequest} request Explicit repository operator input.
 * @returns {void} Throws for unauthorized modes, refs or approval fields.
 */
export function validateRetirementRequest(request) {
    if (!/^release\/v_(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?$/.test(request.branch)
        || !['plan','retire'].includes(request.mode)) {
        throw new Error('BLOCKED retirement requires exactly one canonical release ref and mode.');
    }
    if (request.mode === 'retire' && !request.manualReview.trim()) {
        throw new Error('BLOCKED retirement requires explicit owner acceptance evidence.');
    }
}

/** Fetch bounded workflow activity and fail closed on incomplete branch activity evidence.
 * @param {GithubReader} call GitHub API reader.
 * @param {string} repository Exact repository identity.
 * @param {string} branch Exact release ref.
 * @returns {boolean} True when a matching run or any publication operation remains active.
 */
export function hasActiveReleaseWork(call, repository, branch) {
    for (const state of ['queued','in_progress','waiting','pending']) {
        for (const workflow of ['release-qualification.yml','release-promotion.yml','release-reconciliation.yml']) {
            const list = call('repos/' + repository + '/actions/workflows/' + workflow
                + '/runs?status=' + state + '&per_page=100');
            if (!Array.isArray(list?.workflow_runs) || !Number.isInteger(list.total_count)
                || list.total_count > 100) {
                throw new Error('BLOCKED incomplete active release workflow inventory.');
            }
            // Promotion executes on main, so conservatively block *any* active
            // promotion. Qualification is scoped to the exact release branch.
            if (list.workflow_runs.some(run => workflow !== 'release-qualification.yml'
                || run.head_branch === branch)) return true;
        }
    }
    return false;
}

/** Produce a read-only exact-ref cleanup state, never assuming GitHub approval.
 * @param {RetirementRequest} request Requested release branch.
 * @param {string} repository Owner/repository identity.
 * @param {{api?:GithubReader,evidence?:object}} adapters Inert fixture readers.
 * @returns {{state:string,branch:string,expected_sha:string|null,detail:string}} Safe disposition and leased SHA.
 */
export function planRetirement(request, repository, adapters = {}) {
    validateRetirementRequest(request);
    const call = adapters.api ?? api;
    const snapshot = inspectSync({branch:request.branch,mode:'plan',manualReview:''},repository,adapters);
    if (!['RECONCILED_FF','RECONCILED_EQUIVALENT'].includes(snapshot.state)) {
        return {state:'BLOCKED',branch:request.branch,expected_sha:null,
            detail:'Post-publication develop reconciliation is not fully verified: ' + snapshot.state + '. ' + snapshot.detail};
    }
    const sha = matchingBranchSha(call,repository,request.branch);
    if (!sha) {
        return {state:'ALREADY_DELETED',branch:request.branch,expected_sha:null,
            detail:'The exact release branch no longer exists; immutable tag and publication evidence remain.'};
    }
    if (sha !== snapshot.candidateSha) {
        return {state:'BLOCKED',branch:request.branch,expected_sha:sha,
            detail:'Current release branch SHA differs from the immutable qualified and published candidate.'};
    }
    const open = call('repos/' + repository + '/pulls?state=open&head='
        + encodeURIComponent(repository.split('/')[0] + ':' + request.branch) + '&per_page=100');
    const targeted = call('repos/' + repository + '/pulls?state=open&base='
        + encodeURIComponent(request.branch) + '&per_page=100');
    if (!Array.isArray(open) || open.length >= 100
        || !Array.isArray(targeted) || targeted.length >= 100) {
        throw new Error('BLOCKED truncated or unknown release branch PR dependency inventory.');
    }
    if (open.length !== 0 || targeted.length !== 0 || hasActiveReleaseWork(call,repository,request.branch)) {
        return {state:'BLOCKED',branch:request.branch,expected_sha:sha,
            detail:'An open source PR or an unfinished release workflow still depends on the branch.'};
    }
    return {state:'ELIGIBLE',branch:request.branch,expected_sha:sha,
        detail:'Published immutable release and fully qualified owner-approved linear reconciliation verified; no active dependent workflows.'};
}

/** Require the sole owner's separately approved main-only retirement environment.
 * @param {string} repository Expected owner/name.
 * @param {GithubReader} call Server API reader.
 * @param {{GITHUB_EVENT_NAME?:string,GITHUB_REF?:string,GITHUB_ACTOR?:string}} context Trusted dispatch context.
 * @returns {void} Throws if approval settings or owner authorization are missing.
 */
export function verifyRetirementControls(repository, call, context=process.env) {
    requireOwnerDispatch(repository,call,context);
    requireOwnerEnvironment(call,repository,'release-retirement');
}

/** Apply a Git transport compare-and-swap delete only after independent approval.
 * @param {RetirementRequest} request Explicit operator intent.
 * @param {string} repository GitHub repo.
 * @param {{api?:GithubReader,git?:GitCommand,evidence?:object,context?:object}} adapters Inert fixture mutation boundary.
 * @returns {{state:string,branch:string,expected_sha:string|null,detail:string}} Final result after remote deletion check.
 */
export function retireBranch(request,repository,adapters={}) {
    if (request.mode !== 'retire') throw new Error('BLOCKED branch retirement requires retire mode.');
    const call=adapters.api ?? api;
    const git=adapters.git ?? command;
    const preflight=planRetirement(request,repository,adapters);
    if (preflight.state === 'ALREADY_DELETED') return preflight;
    if (preflight.state !== 'ELIGIBLE') throw new Error('BLOCKED retirement rejected: ' + preflight.detail);
    verifyRetirementControls(repository,call,adapters.context ?? process.env);
    const refreshed=planRetirement(request,repository,adapters);
    if (refreshed.state !== 'ELIGIBLE' || refreshed.expected_sha !== preflight.expected_sha) {
        throw new Error('BLOCKED release branch moved or reconciliation changed during retirement approval.');
    }
    // Server accepts the delete only while the remote branch still equals the
    // explicitly observed immutable candidate. Never force-update a ref.
    git('gh',['auth','setup-git']);
    git('git',['push','--force-with-lease=refs/heads/'+request.branch+':'+refreshed.expected_sha,
        'origin',':refs/heads/'+request.branch]);
    if (matchingBranchSha(call,repository,request.branch)) {
        throw new Error('BLOCKED release branch still exists after attempted leased deletion.');
    }
    return {state:'RETIRED',branch:request.branch,expected_sha:refreshed.expected_sha,
        detail:'Released branch removed with exact ref lease after full reconciliation; immutable release/tag remain.'};
}

/** Dispatch explicit read-only plan or independently authorized retirement.
 * @returns {void} Emits result in the GitHub workflow summary.
 */
export function main() {
    const request={branch:process.env.RELEASE_BRANCH ?? '',mode:process.env.RETIRE_MODE ?? 'plan',
        manualReview:process.env.MANUAL_REVIEW ?? ''};
    validateRetirementRequest(request);
    const repository=process.env.GITHUB_REPOSITORY ?? '';
    if (!/^[\w.-]+\/[\w.-]+$/.test(repository)
        || process.env.GITHUB_EVENT_NAME !== 'workflow_dispatch'
        || process.env.GITHUB_REF !== 'refs/heads/main') {
        throw new Error('BLOCKED retirement requires a reviewed workflow entrypoint on main.');
    }
    const outcome=request.mode === 'retire' ? retireBranch(request,repository) : planRetirement(request,repository);
    output('GITHUB_STEP_SUMMARY','Release branch retirement: '+outcome.state+'\n'
        + 'Branch: '+outcome.branch+'\nExpected SHA: '+(outcome.expected_sha ?? 'none')+'\n'
        + outcome.detail);
    process.stdout.write(JSON.stringify(outcome,null,2)+'\n');
}
if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
    try {main();} catch (error) {process.stderr.write(error.message+'\n');process.exitCode=1;}
}
