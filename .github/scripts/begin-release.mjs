/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/begin-release.mjs
 * Module Type: Release Start Handoff
 * Purpose: Freeze protected develop and explicitly dispatch its release assembly.
 * Responsibilities:
 *   - Keep the selected workflow revision separate from the released source
 *   - Create only the requested release ref and preserve concurrent writers
 *   - Authenticate the maintainer's initiating run across GITHUB_TOKEN dispatch
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {api,output} from './release-promotion.mjs';
import {matchingBranchSha} from './release-reconciliation.mjs';

/** JSON API values narrowed at their owning release boundary.
 * @typedef {import('./release-promotion.mjs').JsonValue} BeginJson
 */
/** GitHub API transport shared by start and handoff verification.
 * @typedef {function(string,string=,(BeginJson|null)=):BeginJson} BeginApi
 */

/** Bound the complete active-run inventory to one supported GitHub page.
 * @type {number}
 * Type: number. Units: runs. Scope: release-start duplicate dispatch guard.
 * Consumers: beginRelease(). Rationale: 100 is GitHub's maximum page size;
 * a full page is refused rather than silently overlooking another active run.
 */
const BEGIN_RELEASE_RUN_PAGE_SIZE=100;

/** Bind the initiating run before resolving the actor of a bot-created dispatch.
 * @param {{version:string,runId:string,attempt:string}} request Version and exact Begin Release attempt.
 * @param {string} repository Expected owner/repository identity.
 * @param {BeginApi} call Read-only server transport.
 * @param {Record<string,string|undefined>} context Actual release-dispatch event context.
 * @returns {string} Server-bound original actor login; the caller must verify maintainer permission. Throws for stale or foreign handoffs.
 */
export function beginReleaseActor(request,repository,call,context) {
    if (!/^[1-9]\d*$/.test(request.runId) || !/^[1-9]\d*$/.test(request.attempt)
        || context.GITHUB_EVENT_NAME!=='workflow_dispatch'
        || context.GITHUB_WORKFLOW_REF!==repository+'/.github/workflows/release-qualification.yml@refs/heads/release/v_'+request.version) {
        throw new Error('BLOCKED invalid Begin Release dispatch identity.');
    }
    const root='repos/'+repository+'/actions/runs/'+request.runId;
    const run=call(root+'/attempts/'+request.attempt),latest=call(root);
    if (String(run.id)!==request.runId || String(run.run_attempt)!==request.attempt
        || latest.run_attempt!==run.run_attempt || run.repository?.full_name!==repository
        || run.path!=='.github/workflows/begin-release.yml' || run.event!=='workflow_dispatch'
        || run.display_title!=='Begin release v_'+request.version
        || !(run.status==='in_progress' || run.status==='completed' && run.conclusion==='success')
        || typeof run.actor?.login!=='string'
        || !['github-actions[bot]',run.actor.login].includes(context.GITHUB_ACTOR)) {
        throw new Error('BLOCKED foreign, failed or superseded Begin Release handoff.');
    }
    return run.actor.login;
}

/** Create or reuse an unmoved release ref and dispatch assembly from its own revision.
 * @param {string} version Canonical version including v_.
 * @param {string} repository Expected owner/repository identity.
 * @param {{api?:BeginApi,context?:Record<string,string|undefined>}} adapters Optional isolated API and actual dispatch context.
 * @returns {{state:string,branch:string,source_sha:string}} Frozen source and dispatch outcome; never writes protected refs or tags.
 */
export function beginRelease(version,repository,adapters={}) {
    const call=adapters.api ?? api,context=adapters.context ?? process.env;
    if (!/^v_(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?$/.test(version)
        || !/^[\w.-]+\/[\w.-]+$/.test(repository)
        || context.GITHUB_EVENT_NAME!=='workflow_dispatch' || !context.GITHUB_REF?.startsWith('refs/heads/')
        || !/^[1-9]\d*$/.test(context.GITHUB_RUN_ID ?? '')
        || !/^[1-9]\d*$/.test(context.GITHUB_RUN_ATTEMPT ?? '')) {
        throw new Error('BLOCKED Begin Release requires a canonical version and branch dispatch.');
    }
    const permission=call('repos/'+repository+'/collaborators/'+encodeURIComponent(context.GITHUB_ACTOR ?? '')+'/permission');
    if (!['admin','maintain'].includes(permission?.permission)) throw new Error('BLOCKED Begin Release requires a maintainer.');
    const source=call('repos/'+repository+'/git/ref/heads/develop');
    if (source.ref!=='refs/heads/develop' || source.object?.type!=='commit' || !/^[a-f0-9]{40}$/.test(source.object?.sha ?? '')) {
        throw new Error('BLOCKED current protected develop source is unavailable.');
    }
    const sha=source.object.sha,branch='release/'+version;
    const tags=call('repos/'+repository+'/git/matching-refs/tags/'+version);
    if (!Array.isArray(tags) || tags.some(row=>row.ref==='refs/tags/'+version)) throw new Error('BLOCKED immutable release tag already exists or inventory is unavailable.');
    const workflow=call('repos/'+repository+'/actions/workflows/release-qualification.yml');
    if (workflow.path!=='.github/workflows/release-qualification.yml' || workflow.state!=='active') {
        throw new Error('BLOCKED release assembly workflow is not registered and active.');
    }
    const runs=call('repos/'+repository+'/actions/workflows/release-qualification.yml/runs?branch='
        +encodeURIComponent(branch)+'&per_page='+BEGIN_RELEASE_RUN_PAGE_SIZE);
    if (!Array.isArray(runs.workflow_runs) || runs.total_count!==runs.workflow_runs.length
        || runs.total_count>=BEGIN_RELEASE_RUN_PAGE_SIZE) throw new Error('BLOCKED incomplete release run inventory.');
    if (runs.workflow_runs.some(run=>run.head_branch===branch && run.head_sha===sha
        && run.path===workflow.path && ['push','workflow_dispatch'].includes(run.event) && run.status!=='completed')) {
        return {state:'ALREADY_RUNNING',branch,source_sha:sha};
    }
    let head=matchingBranchSha(call,repository,branch);
    if (head && head!==sha) throw new Error('BLOCKED existing release has advanced; continue its assembly run instead of restarting Begin Release.');
    const reused=head===sha;
    if (!head) {
        const created=call('repos/'+repository+'/git/refs','POST',{ref:'refs/heads/'+branch,sha});
        if (created.ref!=='refs/heads/'+branch || created.object?.sha!==sha) throw new Error('BLOCKED ambiguous release branch creation; rerun to reconcile server state.');
    }
    head=matchingBranchSha(call,repository,branch);
    if (head!==sha) throw new Error('BLOCKED release branch moved before assembly dispatch.');
    // Unlike a token-created push, workflow_dispatch explicitly starts a run.
    call('repos/'+repository+'/actions/workflows/release-qualification.yml/dispatches','POST',{
        ref:branch,inputs:{begin_source_sha:sha,begin_run:context.GITHUB_RUN_ID,begin_attempt:context.GITHUB_RUN_ATTEMPT},
    });
    return {state:reused?'REUSED_AND_DISPATCHED':'CREATED_AND_DISPATCHED',branch,source_sha:sha};
}

/** Start the requested version and retain a compact frozen-source handoff summary.
 * @returns {void} Writes the release ref and dispatch only; never publishes a release.
 */
export function main() {
    const result=beginRelease(process.env.VERSION ?? '',process.env.GITHUB_REPOSITORY ?? '');
    output('GITHUB_STEP_SUMMARY',JSON.stringify(result,null,2));
}
if (process.argv[1] && import.meta.url===pathToFileURL(resolve(process.argv[1])).href) {
    try {main();} catch (error) {process.stderr.write(error.message+'\n');process.exitCode=1;}
}
