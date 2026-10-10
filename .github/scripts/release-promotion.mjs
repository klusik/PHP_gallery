/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-promotion.mjs
 * Module Type: Hosted Release Orchestration
 * Purpose: Validate exact hosted release evidence and Git identities for automatic completion.
 * Responsibilities:
 *   - Refuse incomplete, stale, foreign or superseded qualification
 *   - Reject red, skipped or stale qualification without overrides
 *   - Verify complete PR detail, parent order and identical content before tagging
 *   - Preserve reviewed historical evidence without publishing GitHub Releases
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {spawnSync} from 'node:child_process';
import {readFileSync,writeFileSync,appendFileSync} from 'node:fs';
import {resolve} from 'node:path';
import {verifyPredecessor} from './release-origin.mjs';
import {requireBotPullRequest,effectiveOwnerReview,qualificationCheckBinding} from './release-owner-authorization.mjs';

/** Recursive JSON transport values; domain validators narrow the fields they consume.
 * @typedef {null|boolean|number|string|JsonValue[]|{[key: string]: JsonValue}} JsonValue
 */
/** Exact immutable qualification inputs; normal automatic completion never overrides red CI.
 * @typedef {{runId:string,candidate:string,branch:string,mode:string,override:boolean,reason:string,acceptedFailures:string[],manualReview:string}} ReleaseRequest
 */
/** GitHub's server-owned job identity and outcome; optional id binds real API rows to their check details, while inert historical fixtures may omit it.
 * @typedef {{id?:number,name:string,status:string,conclusion:string|null,html_url:string}} ReleaseJob
 */
/** Qualification record produced by the aggregate release gate.
 * @typedef {{repository:string,branch:string,candidate_sha:string,source_base:string,version:string,run_id:string,run_attempt:string,ready:boolean,origin_commit_sha:string,selected_develop_sha:string,initial_main_sha:string,previous_stable_tag:string,previous_stable_sha:string,origin_blob_sha:string,previous_tag_object_sha:string,
 * previous_tag_object_type:string,previous_release_candidate_sha:string,previous_tree_sha:string,predecessor_kind:string,
 * automated_result:string,override:boolean,lifecycle_schema?:number,winapp_changed?:boolean}} ReleaseRecord
 */
/** Mandatory historical publication evidence fields consumed by predecessor validation.
 * @typedef {{repository:string,version:string,final_main_sha:string,final_tree:string,candidate_sha:string,
 * ready:boolean,automated_result:string,override:boolean,run_id:string}} PublicationEvidence
 */

/** Execute bounded tooling without a shell or inherited input stream.
 * @param {string} executable Installed command to execute.
 * @param {string[]} args Literal command arguments.
 * @param {string|null} input Optional UTF-8 standard input.
 * @returns {string} Complete Git diff/NUL-delimited bytes as UTF-8, otherwise trimmed stdout; throws on timeout, failure or overflow.
 */
export function command(executable, args, input = null) {
    const result = spawnSync(executable, args, {encoding:'utf8', input, timeout:60000, maxBuffer:16 * 1024 * 1024});
    if (result.error || result.status !== 0) {
        const denied=executable==='gh' && args[0]==='api' && /HTTP (401|403)/.test(result.stderr ?? '');
        const missing=executable==='gh' && args[0]==='api' && /HTTP 404/.test(result.stderr ?? '');
        throw new Error((denied ? 'BLOCKED_API_PERMISSION: ' : missing ? 'BLOCKED_API_NOT_FOUND: ' : 'BLOCKED_COMMAND: ')+executable+' command failed ('+(result.status ?? 'unavailable')+').');
    }
    return executable==='git' && (args.includes('-z') || args[0]==='diff') ? result.stdout : result.stdout.trim();
}


/** Call the repository API with literal paths and JSON request bodies.
 * @param {string} path GitHub REST path relative to the authenticated API.
 * @param {string} method HTTP verb.
 * @param {JsonValue|null} payload Optional structured request payload.
 * @returns {JsonValue} Parsed complete server response.
 */
export function api(path, method = 'GET', payload = null) {
    const args = ['api', path, '--method', method];
    if (payload !== null) args.push('--input', '-');
    return JSON.parse(command('gh', args, payload === null ? null : JSON.stringify(payload)) || 'null');
}


/** Validate exact qualification identifiers and refuse removed write modes or red overrides.
 * @param {ReleaseRequest} request Read-only no-override qualification inputs.
 * @returns {string} Version encoded in the validated release branch.
 */
export function validateRequest(request) {
    const match = /^release\/v_((?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?)$/.exec(request.branch);
    if (!match || !/^[a-f0-9]{40}$/.test(request.candidate) || !/^[1-9]\d*$/.test(request.runId)) {
        throw new Error('BLOCKED invalid release branch, exact SHA or qualification run.');
    }
    if (request.mode !== 'plan') throw new Error('BLOCKED unknown release action.');
    if (request.override) throw new Error('BLOCKED release qualification overrides are unsupported.');
    if (request.reason.trim() || request.acceptedFailures.length) {
        throw new Error('BLOCKED override fields supplied without explicit acknowledgement.');
    }
    return match[1];
}


/** Require the final main merge commit to contain exactly the qualified Git tree.
 * @param {string} mainSha Current main commit SHA.
 * @param {string} mergeSha Server-recorded release PR merge commit SHA.
 * @param {string} mainTree Current main's complete Git tree SHA.
 * @param {string} candidateTree Qualified candidate's complete Git tree SHA.
 * @returns {void} Throws on a moved branch or any different content.
 */
export function verifyFinalContent(mainSha, mergeSha, mainTree, candidateTree) {
    if (mainSha !== mergeSha || mainTree !== candidateTree) {
        throw new Error('BLOCKED final main tree differs or main advanced after promotion.');
    }
}


/** Require current GitHub Actions checks bound to the exact SHA, requested run and attempt.
 * @param {function(string):JsonValue} call Read-only server API.
 * @param {string} repository Exact owner/repository.
 * @param {ReleaseRequest} request Exact qualification identity.
 * @param {string} runAttempt Exact server-validated qualification attempt from the retained record.
 * @returns {void} Throws for unavailable, foreign, stale, pending or red status checks.
 */
export function requireQualificationChecks(call,repository,request,runAttempt) {
    const checks=call('repos/'+repository+'/commits/'+request.candidate+'/check-runs?per_page=100&filter=all');
    if (!Array.isArray(checks?.check_runs) || !Number.isInteger(checks.total_count) || checks.total_count>=100
        || checks.total_count!==checks.check_runs.length) {
        throw new Error('BLOCKED exact qualification check inventory unavailable or incomplete.');
    }
    for (const name of ['Release qualification','Complete required CI matrix']) {
        // Count every matching release identity before validating its App and outcome.
        // Independent PR checks with this display name cannot authorize or invalidate Q.
        const identity='release:'+request.runId+':'+runAttempt+':'+request.candidate;
        const matches=checks.check_runs.filter(check=>check.name===name && check.external_id===identity);
        const superseded=checks.check_runs.some(check=>{
            const newer=qualificationCheckBinding(check.external_id);
            return check.name===name && check.app?.id===15368 && check.head_sha===request.candidate
                && newer?.kind==='release' && newer.sha===request.candidate && check.id>matches[0]?.id;
        });
        const binding=qualificationCheckBinding(matches[0]?.external_id);
        if (matches.length!==1 || superseded || matches[0].app?.id!==15368 || matches[0].head_sha!==request.candidate
            || matches[0].status!=='completed' || matches[0].conclusion!=='success'
            || binding?.kind!=='release' || binding.runId!==request.runId || binding.sha!==request.candidate
            || String(binding.attempt)!==runAttempt) {
            throw new Error('BLOCKED missing, stale or red exact qualification check: '+name);
        }
    }
}


/** Enforce coverage owners and exact attempt/candidate binding using server evidence.
 * @param {ReleaseRequest} request Authorized immutable inputs.
 * @param {{path:string,event:string,head_branch:string,status:string,conclusion:string|null,run_attempt:number}} run Server workflow attempt metadata.
 * @param {ReleaseJob[]} jobs Complete server job list for the current attempt.
 * @param {ReleaseRecord} record Downloaded aggregate qualification record.
 * @param {string} repository Expected owner/repository identity.
 * @param {string} currentHead Current release branch head observed from GitHub.
 * @returns {ReleaseJob[]} Empty collection after complete successful qualification; throws for any mandatory failure or missing coverage.
 */
export function verifyQualification(request, run, jobs, record, repository, currentHead) {
    const version = validateRequest(request);
    if (run.path !== '.github/workflows/release-qualification.yml'
        || !['push','workflow_dispatch'].includes(run.event) || run.head_branch !== request.branch
        || run.status !== 'completed' || !['success','failure'].includes(run.conclusion)) {
        throw new Error('BLOCKED foreign, unfinished or cancelled release qualification.');
    }
    if (record.repository !== repository || record.branch !== request.branch
        || record.candidate_sha !== request.candidate || record.run_id !== request.runId
        || record.run_attempt !== String(run.run_attempt) || record.version !== version
        || record.ready !== true || record.automated_result !== 'success' || record.override !== false
        || !/^[a-f0-9]{40}$/.test(record.source_base) || currentHead !== request.candidate) {
        throw new Error('BLOCKED stale or unbound prepared candidate evidence.');
    }
    if (![record.origin_commit_sha,record.selected_develop_sha,record.initial_main_sha,
        record.previous_stable_sha].every(value => typeof value === 'string' && /^[a-f0-9]{40}$/.test(value))
        || record.source_base !== record.previous_release_candidate_sha
        || record.previous_stable_sha !== record.initial_main_sha
        || ![record.previous_tag_object_sha,record.previous_tree_sha,record.previous_release_candidate_sha,record.origin_blob_sha].every(value=>/^[a-f0-9]{40}$/.test(value ?? ''))
        || typeof record.previous_stable_tag !== 'string'
        || !/^v_(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?$/.test(record.previous_stable_tag)) {
        throw new Error('BLOCKED qualification lacks immutable selected-develop and prior-main provenance.');
    }
    const owners = [
        'Prepare release candidate', 'Read-only generated-state and source preflight',
        'Positive production package (ubuntu-24.04)', 'Positive production package (windows-2025)',
        'Positive production package (macos-latest)', 'PHP 8.3 workflows (mysql:8.4, Unicode)',
        'PHP 8.3 workflows (mariadb:10.11, Unicode)', 'PHP 8.5 workflows (mariadb:11.4, Unicode)',
        'PHP 8.1 source (unicode)', 'PHP 8.5 source (ascii)', 'Required Chromium fixtures',
        'Authoritative release audit', 'Complete required CI matrix', 'Release qualification gate',
    ];
    if (record.lifecycle_schema === 2) {
        owners.push('Prepare manual release assets','Open qualified release PR');
        if (record.winapp_changed === true) owners.push('Prepare Windows companion installer');
        else if (record.winapp_changed !== false) throw new Error('BLOCKED installer impact is unknown.');
    }
    for (const owner of owners) {
        const matches = jobs.filter(job => job.name === owner || job.name.endsWith(` / ${owner}`));
        if (matches.length !== 1 || matches[0].status !== 'completed') throw new Error(`BLOCKED missing/ambiguous required job: ${owner}`);
    }
    const prepare = jobs.find(job => job.name === 'Prepare release candidate');
    if (prepare.conclusion !== 'success') throw new Error('BLOCKED preparation integrity cannot be overridden.');
    const failures = jobs.filter(job => job.conclusion !== 'success'
        && !(record.lifecycle_schema === 2 && record.winapp_changed === false
            && job.name === 'Prepare Windows companion installer' && job.status === 'completed' && job.conclusion === 'skipped'));
    if (run.conclusion !== 'success' || failures.length) throw new Error('BLOCKED mandatory release CI is red.');
    return failures;
}


/** Read complete attempt coverage without counting its bound programmatic qualification checks as workflow jobs.
 * @param {string} repository Owner/repository identity.
 * @param {string} runId Server workflow run identifier.
 * @param {number} attempt Current immutable attempt number.
 * @param {string} candidate Exact Q SHA carried by the retained qualification record.
 * @param {function(string,string[],(string|null)=):string} execute Literal paginated transport; optional stdin defaults to null.
 * @param {function(string):JsonValue} call Server check-detail reader used to identify programmatic aggregate rows.
 * @returns {ReleaseJob[]} Complete workflow coverage, excluding only successful exact release checks independently required by requireQualificationChecks; foreign/unbound rows remain visible and malformed evidence throws.
 */
export function fetchJobs(repository, runId, attempt, candidate, execute=command, call=api) {
    if (!/^[\w.-]+\/[\w.-]+$/.test(repository) || !/^[1-9]\d*$/.test(runId)
        || !Number.isSafeInteger(attempt) || attempt<1 || !/^[a-f0-9]{40}$/.test(candidate)) {
        throw new Error('BLOCKED invalid exact job inventory identity.');
    }
    const pages=JSON.parse(execute('gh',['api',`repos/${repository}/actions/runs/${runId}/attempts/${attempt}/jobs?per_page=100`,'--paginate','--slurp']));
    if (!Array.isArray(pages) || !pages.length || pages.some(page=>!page || !Array.isArray(page.jobs))) {
        throw new Error('BLOCKED malformed job inventory pages.');
    }
    const jobs=pages.flatMap(page=>page.jobs);
    if (jobs.some(job=>!job || typeof job.name!=='string' || typeof job.status!=='string')) {
        throw new Error('BLOCKED malformed job inventory fields.');
    }
    if (pages.some(page=>page.total_count!==jobs.length)) throw new Error('BLOCKED incomplete job inventory.');
    return workflowCoverageJobs(jobs,repository,runId,attempt,candidate,'release',call);
}

/** Separate workflow coverage from successful programmatic checks bound to the exact candidate attempt.
 * @param {ReleaseJob[]} jobs Complete server-owned Actions inventory, whose completeness is checked by its reader.
 * @param {string} repository Exact owner/repository.
 * @param {string} runId Immutable qualification run ID.
 * @param {number} attempt Exact server attempt.
 * @param {string} candidate Exact qualified candidate SHA.
 * @param {'candidate'|'release'} kind Qualification owner used by current or retained historical evidence.
 * @param {function(string):JsonValue} call Read-only check detail adapter.
 * @returns {ReleaseJob[]} Required workflow rows and all foreign/unbound rows; only independently validated exact successful qualification checks are excluded, and mismatched identities throw.
 */
export function workflowCoverageJobs(jobs,repository,runId,attempt,candidate,kind,call=api) {
    if (!['candidate','release'].includes(kind)) throw new Error('BLOCKED unknown qualification coverage owner.');
    return jobs.filter(job=>{
        if (![kind==='release'?'Release qualification':'Candidate qualification','Complete required CI matrix'].includes(job.name)
            || !Number.isSafeInteger(job.id) || job.id<1) return true;
        // GitHub includes POST /check-runs rows in the Actions jobs endpoint.
        // Authenticate their exact external binding; display names alone cannot omit coverage.
        const check=call('repos/'+repository+'/check-runs/'+job.id);
        if (check.id!==job.id || check.name!==job.name) throw new Error('BLOCKED job/check identity differs.');
        return !(check.external_id===kind+':'+runId+':'+attempt+':'+candidate
            && check.app?.id===15368 && check.head_sha===candidate
            && check.status==='completed' && check.conclusion==='success');
    });
}


/** Read complete release inventory through the existing bounded GitHub transport.
 * @param {string} repository Exact owner/repository identity.
 * @param {function(string,string[],(string|null)=):string} execute Command adapter; its optional stdin defaults to null.
 * @returns {Array<{id:number,tag_name:string,draft:boolean,prerelease:boolean,html_url:string,assets:Array<{name:string,size:number,digest?:string|null}>}>} All server releases and their assets; throws for failed or malformed pagination.
 */
export function fetchReleases(repository,execute=command) {
    const pages=JSON.parse(execute('gh',['api',`repos/${repository}/releases?per_page=100`,'--paginate','--slurp']));
    if (!Array.isArray(pages) || pages.length===0 || pages.some(page=>!Array.isArray(page) || page.length>100
        || page.some(release=>!release || !Number.isInteger(release.id) || typeof release.tag_name!=='string'
            || typeof release.draft!=='boolean' || typeof release.prerelease!=='boolean' || !Array.isArray(release.assets)))) {
        throw new Error('BLOCKED release inventory unavailable or malformed.');
    }
    return pages.flat();
}


/** Append an escaped workflow output or human-readable summary in the owned runner file.
 * @param {string} variable GitHub environment filename variable.
 * @param {string} value Complete append-only UTF-8 contents.
 * @returns {void} Writes only when GitHub supplies the destination.
 */
export function output(variable, value) {
    const path = process.env[variable];
    if (path) appendFileSync(path, value + '\n');
}


/** Preserve a read-only, fail-closed recovery decision for an advanced main.
 * @param {ReleaseRequest} request Immutable candidate/qualification identity.
 * @param {string} repository Expected owner/repository.
 * @param {string} mainSha Observed current main SHA.
 * @param {string} reason Concrete stale-base failure.
 * @param {function(string,string=,(JsonValue|null)=):JsonValue} call Server-owned read-only GitHub API.
 * @returns {void} Always rejects promotion after optionally recording bounded evidence.
 */
export function refuseAdvancedMain(request,repository,mainSha,reason,call=api) {
    const observed=call('repos/'+repository+'/commits/main');
    const current=observed?.sha;
    const tree=observed?.commit?.tree?.sha;
    const record={
        state:'BLOCKED_MAIN_ADVANCED',next_required_state:'NEW_CANDIDATE_REQUIRED',
        supersession:'PENDING_MAINTAINER_REVIEW',
        original_release_branch:request.branch,original_qualified_candidate_sha:request.candidate,
        original_qualification_run_id:request.runId,
        observed_main_sha:current,observed_main_tree:tree,previous_observed_main_sha:mainSha,
        reason,repair:'Keep original qualification evidence; select and review a new release source SHA, '
            +'reapply reviewed changes, regenerate artifacts, rerun full exact-SHA CI, then approve a new PR. '
            +'Mark the original candidate SUPERSEDED only after a human records the replacement.'
    };
    if (process.env.RUNNER_TEMP && /^[a-f0-9]{40}$/.test(current ?? '')
        && /^[a-f0-9]{40}$/.test(tree ?? '')) {
        writeFileSync(resolve(process.env.RUNNER_TEMP,'release-recovery.json'),JSON.stringify(record,null,2)+'\n');
    }
    output('GITHUB_STEP_SUMMARY',JSON.stringify(record,null,2));
    throw new Error('BLOCKED_MAIN_ADVANCED: '+reason+'; '
        + 'qualification '+request.runId+', candidate '+request.candidate
        + ', observed main '+(current ?? 'UNKNOWN')
        + ', tree '+(tree ?? 'UNKNOWN')+'. Reviewed NEW_CANDIDATE_REQUIRED; no branch or tag changed.');
}


/** Inspect main lineage before claiming a qualified candidate can still be promoted.
 * @param {ReleaseRequest} request Explicit qualified candidate.
 * @param {string} repository Owner/repository.
 * @param {{api:function(string,string=,(JsonValue|null)=):JsonValue,record?:ReleaseRecord,evidence?:Record<string,unknown>}|null} adapters Inert test server adapter when supplied.
 * @returns {string} Exact selected predecessor main SHA; candidate ancestry is independent.
 */
export function requireCurrentMainBase(request, repository, adapters = null) {
    const call = adapters?.api ?? api;
    const sha = call(`repos/${repository}/git/ref/heads/main`).object?.sha;
    if (!/^[a-f0-9]{40}$/.test(sha)) throw new Error('BLOCKED_MAIN_ADVANCED: current main SHA is unavailable.');
    const record = adapters?.record ?? JSON.parse(readFileSync(resolve(process.env.RELEASE_RECORD ?? 'release-record/release-candidate.json'),'utf8'));
    if (sha !== record.initial_main_sha || record.previous_stable_sha !== sha) {
        refuseAdvancedMain(request,repository,sha,'current main differs from the exact selected published predecessor',call);
    }
    const predecessor = verifyPredecessor(call,repository,record.previous_stable_tag,adapters ?? {});
    for (const [key,value] of Object.entries(predecessor)) {
        if (record[key] !== value) throw new Error('BLOCKED qualification predecessor evidence differs: '+key);
    }
    if (call(`repos/${repository}/git/ref/heads/main`).object?.sha !== sha) {
        refuseAdvancedMain(request,repository,sha,'main changed during promotion lineage inspection',call);
    }
    return sha;
}


/** Read-only verification of the actual merged bot PR and latest owner review.
 * Discover one merged PR in the listing, then bind its complete detail before
 * using the detail-only merger identity and the SHA-bound owner approval.
 * @param {ReleaseRequest} request Exact qualified candidate and branch.
 * @param {string} repository Expected owner/name.
 * @param {{api?:function(string,string=,(JsonValue|null)=):JsonValue}} adapters Inert test reader, when supplied.
 * @returns {{pr:{number:number,user:{login:string},merge_commit_sha:string,merged_at:string,html_url:string},ownerReview:{id:number,reviewer:string,commit_sha:string,submitted_at:string,url:string|null},main:{sha:string,commit:{tree:{sha:string}}},candidate:{sha:string,commit:{tree:{sha:string}}}}} Verified server-bound published-input identity.
 */
export function inspectMergedPromotion(request,repository,adapters=null) {
    const call=adapters?.api ?? api;
    const owner=repository.split('/')[0];
    const readPr=path=>{
        try { return call(path); }
        catch (error) { throw new Error('BLOCKED release promotion PR API unavailable.',{cause:error}); }
    };
    const pulls=readPr('repos/'+repository+'/pulls?state=closed&base=main&head='
        + encodeURIComponent(owner+':'+request.branch)+'&per_page=100');
    if (!Array.isArray(pulls) || pulls.length > 1) {
        throw new Error('BLOCKED ambiguous or truncated release promotion PR inventory.');
    }
    const listed=pulls.find(item=>item?.merged_at);
    if (!listed) throw new Error('BLOCKED exact release candidate has not been promoted through a PR.');
    requireBotPullRequest(listed,repository,request.candidate,'main');
    if (!Number.isSafeInteger(listed.number) || typeof listed.node_id!=='string' || !listed.node_id) {
        throw new Error('BLOCKED release promotion PR identity unavailable in the listing.');
    }
    // GitHub's PR listing omits merged_by; only the bound detail can prove the merger.
    const pr=readPr('repos/'+repository+'/pulls/'+listed.number);
    requireBotPullRequest(pr,repository,request.candidate,'main');
    const identities=[
        [listed.node_id,pr.node_id],
        [listed.head?.ref,pr.head?.ref],[listed.head?.sha,pr.head?.sha],
        [listed.head?.repo?.full_name,pr.head?.repo?.full_name],
        [listed.base?.ref,pr.base?.ref],[listed.base?.sha,pr.base?.sha],
        [listed.base?.repo?.full_name,pr.base?.repo?.full_name],
        [listed.user?.login,pr.user?.login],[listed.merge_commit_sha,pr.merge_commit_sha],
        [listed.merged_at,pr.merged_at],[listed.created_at,pr.created_at],
        [listed.html_url,pr.html_url],
    ];
    if (pr.number!==listed.number || listed.state!=='closed' || pr.state!=='closed' || pr.merged!==true
        || pr.head.ref!==request.branch || pr.base.repo?.full_name!==repository
        || !identities.every(([summary,detail])=>typeof summary==='string' && summary.length>0 && summary===detail)
        || !Number.isFinite(Date.parse(pr.merged_at)) || !Number.isFinite(Date.parse(pr.created_at))
        || (listed.merged_by!==undefined && listed.merged_by?.login!==pr.merged_by?.login)) {
        throw new Error('BLOCKED release promotion PR list/detail identity differs or is incomplete.');
    }
    if (typeof pr.merged_by?.login!=='string' || ![owner.toLowerCase(),'github-actions[bot]'].includes(pr.merged_by.login.toLowerCase())) {
        throw new Error('BLOCKED release PR merger must be the reviewed owner or workflow bot.');
    }
    const reviews=call('repos/'+repository+'/pulls/'+pr.number+'/reviews?per_page=100');
    const ownerReview=effectiveOwnerReview(reviews,pr,owner,request.candidate);
    if (!ownerReview) throw new Error('BLOCKED no effective owner PR approval for the exact qualified candidate.');
    const main=call('repos/'+repository+'/commits/main');
    const candidate=call('repos/'+repository+'/commits/'+request.candidate);
    const record = adapters?.record ?? JSON.parse(readFileSync(resolve(process.env.RELEASE_RECORD ?? 'release-record/release-candidate.json'),'utf8'));
    if (main.parents?.length !== 2 || main.parents[0]?.sha !== record.initial_main_sha
        || main.parents[1]?.sha !== request.candidate) {
        throw new Error('BLOCKED final release merge parents or reviewed PR base differ.');
    }
    const predecessor = verifyPredecessor(call,repository,record.previous_stable_tag,adapters ?? {});
    for (const [key,value] of Object.entries(predecessor)) {
        if (record[key] !== value) throw new Error('BLOCKED published predecessor changed after qualification.');
    }
    verifyFinalContent(main.sha,pr.merge_commit_sha,main.commit.tree.sha,candidate.commit.tree.sha);
    return {pr,ownerReview,main,candidate};
}
