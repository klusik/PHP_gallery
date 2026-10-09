/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-promotion.mjs
 * Module Type: Hosted Release Orchestration
 * Purpose: Bind protected promotion and publication to immutable hosted evidence.
 * Responsibilities:
 *   - Refuse incomplete, stale, foreign or superseded qualification
 *   - Keep maintainer overrides explicit without changing red checks
 *   - Promote through a PR and verify identical final content before immutable tagging
 *   - Publish packages and permanent qualification evidence idempotently
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {spawnSync} from 'node:child_process';
import {readFileSync, writeFileSync, appendFileSync, readdirSync} from 'node:fs';
import {resolve, basename} from 'node:path';
import {pathToFileURL} from 'node:url';
import {createHash} from 'node:crypto';
import {requireOwnerDispatch,requireOwnerEnvironment,requireOwnerRuleset,requireBotPullRequest,effectiveOwnerReview} from './release-owner-authorization.mjs';

/** Recursive JSON transport values; domain validators narrow the fields they consume.
 * @typedef {null|boolean|number|string|JsonValue[]|{[key: string]: JsonValue}} JsonValue
 */
/** Requested immutable qualification and separately authorized maintainer action.
 * @typedef {{runId:string,candidate:string,branch:string,mode:string,override:boolean,reason:string,acceptedFailures:string[],manualReview:string}} ReleaseRequest
 */
/** GitHub's server-owned job identity and outcome for one workflow attempt.
 * @typedef {{name:string,status:string,conclusion:string|null,html_url:string}} ReleaseJob
 */
/** Qualification record produced by the aggregate release gate.
 * @typedef {{repository:string,branch:string,candidate_sha:string,source_base:string,version:string,run_id:string,run_attempt:string,ready:boolean,origin_commit_sha:string,selected_develop_sha:string,initial_main_sha:string,previous_stable_tag:string,previous_stable_sha:string}} ReleaseRecord
 */

/** Execute bounded tooling without a shell or inherited input stream.
 * @param {string} executable Installed command to execute.
 * @param {string[]} args Literal command arguments.
 * @param {string|null} input Optional UTF-8 standard input.
 * @returns {string} Complete stdout; throws on timeout, failure or overflow.
 */
export function command(executable, args, input = null) {
    const result = spawnSync(executable, args, {encoding:'utf8', input, timeout:60000, maxBuffer:16 * 1024 * 1024});
    if (result.error || result.status !== 0) {
        throw new Error(`BLOCKED ${executable} command failed (${result.status ?? 'unavailable'}).`);
    }
    return result.stdout.trim();
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

/** Validate identifiers and require explicit human review for write modes.
 * @param {ReleaseRequest} request Maintainer dispatch inputs.
 * @returns {string} Version encoded in the validated release branch.
 */
export function validateRequest(request) {
    const match = /^release\/v_((?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?)$/.exec(request.branch);
    if (!match || !/^[a-f0-9]{40}$/.test(request.candidate) || !/^[1-9]\d*$/.test(request.runId)) {
        throw new Error('BLOCKED invalid release branch, exact SHA or qualification run.');
    }
    if (!['plan','promote','publish'].includes(request.mode)) throw new Error('BLOCKED unknown release action.');
    if (request.mode !== 'plan' && !request.manualReview.trim()) throw new Error('BLOCKED human acceptance evidence is required.');
    if (request.override && (!request.reason.trim() || request.acceptedFailures.length === 0)) {
        throw new Error('BLOCKED override requires a reason and exact failed job names.');
    }
    if (!request.override && (request.reason.trim() || request.acceptedFailures.length)) {
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

/** Enforce coverage owners and exact attempt/candidate binding using server evidence.
 * @param {ReleaseRequest} request Authorized immutable inputs.
 * @param {{path:string,event:string,head_branch:string,status:string,conclusion:string|null,run_attempt:number}} run Server workflow attempt metadata.
 * @param {ReleaseJob[]} jobs Complete server job list for the current attempt.
 * @param {ReleaseRecord} record Downloaded aggregate qualification record.
 * @param {string} repository Expected owner/repository identity.
 * @param {string} currentHead Current release branch head observed from GitHub.
 * @returns {ReleaseJob[]} Red/skipped/cancelled jobs explicitly accepted by an override, otherwise empty.
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
        || record.ready !== true || !/^[a-f0-9]{40}$/.test(record.source_base) || currentHead !== request.candidate) {
        throw new Error('BLOCKED stale or unbound prepared candidate evidence.');
    }
    if (![record.origin_commit_sha,record.selected_develop_sha,record.initial_main_sha,
        record.previous_stable_sha].every(value => typeof value === 'string' && /^[a-f0-9]{40}$/.test(value))
        || record.source_base !== record.previous_stable_sha
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
    for (const owner of owners) {
        const matches = jobs.filter(job => job.name === owner || job.name.endsWith(` / ${owner}`));
        if (matches.length !== 1 || matches[0].status !== 'completed') throw new Error(`BLOCKED missing/ambiguous required job: ${owner}`);
    }
    const prepare = jobs.find(job => job.name === 'Prepare release candidate');
    if (prepare.conclusion !== 'success') throw new Error('BLOCKED preparation integrity cannot be overridden.');
    const failures = jobs.filter(job => job.conclusion !== 'success');
    if (!request.override && (run.conclusion !== 'success' || failures.length)) throw new Error('BLOCKED mandatory release CI is red.');
    if (request.override) {
        const actual = failures.map(job => job.name).sort();
        const accepted = [...new Set(request.acceptedFailures)].sort();
        if (!actual.length || JSON.stringify(actual) !== JSON.stringify(accepted)) {
            throw new Error('BLOCKED override must acknowledge every exact red/skipped job and no unrelated jobs.');
        }
    }
    return failures;
}

/** Read all jobs from the specified workflow attempt, without first-page truncation.
 * @param {string} repository Owner/repository identity.
 * @param {string} runId Server workflow run identifier.
 * @param {number} attempt Current immutable attempt number.
 * @returns {ReleaseJob[]} Complete attempt-specific job evidence.
 */
export function fetchJobs(repository, runId, attempt) {
    const pages = JSON.parse(command('gh', ['api', `repos/${repository}/actions/runs/${runId}/attempts/${attempt}/jobs?per_page=100`, '--paginate', '--slurp']));
    return pages.flatMap(page => page.jobs);
}

/** Read dispatch inputs from environment without evaluating their content.
 * @returns {ReleaseRequest} Normalized request; further validation remains mandatory.
 */
export function readRequest() {
    return {runId:process.env.QUALIFICATION_RUN ?? '', candidate:process.env.CANDIDATE_SHA ?? '',
        branch:process.env.RELEASE_BRANCH ?? '', mode:process.env.RELEASE_MODE ?? 'plan',
        override:process.env.OVERRIDE_ACK === 'true', reason:process.env.OVERRIDE_REASON ?? '',
        acceptedFailures:(process.env.ACCEPTED_FAILURES ?? '').split('\n').map(value => value.trim()).filter(Boolean),
        manualReview:process.env.MANUAL_REVIEW ?? ''};
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

/** Refuse write authority without reviewed environment and protected PR/status policy.
 * @param {string} repository Owner/repository identity.
 * @returns {void} Throws unless server controls and maintainer identity are established.
 */
export function verifyWriteControls(repository) {
    requireOwnerDispatch(repository,api,process.env);
    requireOwnerEnvironment(api,repository,'release-promotion');
    requireOwnerRuleset(api,repository,'main','Reviewed release promotion to main','Release qualification');
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
 * @param {{api:function(string,string=,(JsonValue|null)=):JsonValue}|null} adapters Inert test server adapter when supplied.
 * @returns {string} Exact main SHA observed together with positive branch ancestry.
 */
export function requireCurrentMainBase(request, repository, adapters = null) {
    const call = adapters?.api ?? api;
    const sha = call(`repos/${repository}/git/ref/heads/main`).object?.sha;
    if (!/^[a-f0-9]{40}$/.test(sha)) throw new Error('BLOCKED_MAIN_ADVANCED: current main SHA is unavailable.');
    const comparison = call(`repos/${repository}/compare/main...${request.candidate}`);
    if (!Number.isInteger(comparison.behind_by) || comparison.behind_by !== 0) {
        refuseAdvancedMain(request,repository,sha,'main is no longer an ancestor of the exact qualified candidate',call);
    }
    if (call(`repos/${repository}/git/ref/heads/main`).object?.sha !== sha) {
        refuseAdvancedMain(request,repository,sha,'main changed during promotion lineage inspection',call);
    }
    return sha;
}

/** Create/reuse the release PR and request protected SHA-bound promotion.
 * @param {ReleaseRequest} request Checked maintainer request.
 * @param {string} repository Owner/repository identity.
 * @param {ReleaseJob[]} failures Original accepted red evidence.
 * @param {{api:function(string,string=,(JsonValue|null)=):JsonValue,command:function(string,string[],(string|null)=):string}|null} adapters Optional inert API/command adapters for hosted regression tests; API method defaults to GET and command input defaults to null.
 * @returns {Promise<void>} Completes PR/auto-merge setup or the explicit override merge.
 */
export async function promote(request, repository, failures, adapters = null) {
    if (request.override || failures.length) {
        throw new Error('BLOCKED owner-only promotion cannot bypass any required red or skipped CI job.');
    }
    const call = adapters?.api ?? api;
    const owner = repository.split('/')[0];
    const observedMainSha = requireCurrentMainBase(request,repository,adapters);
    const pulls = call('repos/' + repository + '/pulls?state=all&base=main&head='
        + encodeURIComponent(owner + ':' + request.branch) + '&per_page=100');
    if (!Array.isArray(pulls) || pulls.length > 1) {
        throw new Error('BLOCKED ambiguous or incomplete release PR inventory.');
    }
    let pr = pulls[0] ?? null;
    if (pr && pr.state !== 'open' && !pr.merged_at) {
        throw new Error('BLOCKED previous release PR was closed without merge; manual recovery required.');
    }
    const body = 'Prepared candidate: ' + request.candidate + '\n'
        + 'Qualification: https://github.com/' + repository + '/actions/runs/' + request.runId + '\n'
        + 'Owner dispatch acceptance: ' + request.manualReview + '\n'
        + 'Manual PR approval and merge by ' + owner + ' are REQUIRED after all GitHub CI passes.\n'
        + 'No automatic merge, red-check override or protected-branch bypass is authorized.\n'
        + 'Refs #101 #133';
    if (!pr) {
        pr = call('repos/' + repository + '/pulls','POST',{
            title:'Release v_' + validateRequest(request),head:request.branch,base:'main',
            body,maintainer_can_modify:false});
    }
    requireBotPullRequest(pr,repository,request.candidate,'main');
    if (pr.merged_at) return;
    if (call('repos/' + repository + '/git/ref/heads/' + request.branch).object?.sha !== request.candidate) {
        throw new Error('BLOCKED stale release candidate after PR creation.');
    }
    if (requireCurrentMainBase(request,repository,adapters) !== observedMainSha) {
        refuseAdvancedMain(request,repository,observedMainSha,
            'main changed after creation of the bot-authored PR',call);
    }
    output('GITHUB_STEP_SUMMARY','Review and manually merge release PR: '+pr.html_url+'\n'
        + 'Owner approver: '+owner+'\nExact qualified candidate: '+request.candidate+'\n'
        + 'PR CI may first require an explicit Approve workflows to run action.\n'
        + 'Do not run publish before a real owner approval and protected merge.');
}

/** Read-only verification of the actual merged bot PR and latest owner review.
 * @param {ReleaseRequest} request Exact qualified candidate and branch.
 * @param {string} repository Expected owner/name.
 * @param {{api?:function(string,string=,(JsonValue|null)=):JsonValue}} adapters Inert test reader, when supplied.
 * @returns {{pr:{number:number,user:{login:string},merge_commit_sha:string,merged_at:string,html_url:string},ownerReview:{id:number,reviewer:string,commit_sha:string,submitted_at:string,url:string|null},main:{sha:string,commit:{tree:{sha:string}}},candidate:{sha:string,commit:{tree:{sha:string}}}}} Verified server-bound published-input identity.
 */
export function inspectMergedPromotion(request,repository,adapters=null) {
    const call=adapters?.api ?? api;
    const owner=repository.split('/')[0];
    const pulls=call('repos/'+repository+'/pulls?state=closed&base=main&head='
        + encodeURIComponent(owner+':'+request.branch)+'&per_page=100');
    if (!Array.isArray(pulls) || pulls.length > 1) {
        throw new Error('BLOCKED ambiguous or truncated release promotion PR inventory.');
    }
    const pr=pulls.find(item=>item.merged_at);
    if (!pr) throw new Error('BLOCKED exact release candidate has not been promoted through a PR.');
    requireBotPullRequest(pr,repository,request.candidate,'main');
    const reviews=call('repos/'+repository+'/pulls/'+pr.number+'/reviews?per_page=100');
    const ownerReview=effectiveOwnerReview(reviews,pr,owner,request.candidate);
    if (!ownerReview) throw new Error('BLOCKED no effective owner PR approval for the exact qualified candidate.');
    const main=call('repos/'+repository+'/commits/main');
    const candidate=call('repos/'+repository+'/commits/'+request.candidate);
    verifyFinalContent(main.sha,pr.merge_commit_sha,main.commit.tree.sha,candidate.commit.tree.sha);
    return {pr,ownerReview,main,candidate};
}

/** Verify release assets and upload permanent evidence before making a release public.
 * @param {ReleaseRequest} request Checked maintainer request.
 * @param {string} repository Owner/repository identity.
 * @param {ReleaseRecord} record Bound qualification identity.
 * @param {ReleaseJob[]} failures Original accepted red evidence.
 * @param {{api:function(string,string=,(JsonValue|null)=):JsonValue,command:function(string,string[],(string|null)=):string}|null} adapters Optional inert API/command adapters for hosted regression tests; API method defaults to GET and command input defaults to null.
 * @returns {Promise<void>} Publishes/reconciles only the verified immutable release.
 */
export async function publish(request, repository, record, failures, adapters = null) {
    if (request.override || failures.length) {
        throw new Error('BLOCKED owner-only publication cannot bypass a failed mandatory qualification.');
    }
    const call = adapters?.api ?? api;
    const execute = adapters?.command ?? command;
    const {pr,ownerReview,main}=inspectMergedPromotion(request,repository,adapters);
    const directory = resolve(process.env.RELEASE_ASSETS ?? 'release-assets');
    const files = readdirSync(directory).filter(name => name !== 'release-evidence.json').sort();
    for (const required of ['production.zip','core-manifest.json','production-files.json','release-metadata.json','release-notes.md','SHA256SUMS','qualification-evidence.zip','final-integrity.json']) {
        if (!files.includes(required)) throw new Error(`BLOCKED missing publication asset ${required}.`);
    }
    const integrity = JSON.parse(readFileSync(resolve(directory, 'final-integrity.json'), 'utf8'));
    if (integrity.candidate_sha !== request.candidate || integrity.source_base !== record.source_base || integrity.result !== 'PASS') throw new Error('BLOCKED final integrity evidence is unbound.');
    for (const [name, expected] of Object.entries(integrity.hashes)) {
        if (basename(name) !== name || createHash('sha256').update(readFileSync(resolve(directory,name))).digest('hex') !== expected) throw new Error('BLOCKED publication asset bytes changed.');
    }
    const changed = call(`repos/${repository}/compare/${record.source_base}...${request.candidate}`);
    if (changed.total_commits > 250 || !Array.isArray(changed.files) || changed.files.length >= 300) throw new Error('BLOCKED truncated WinApp scope comparison.');
    if (changed.files.some(file => /^winapp\/(?!tests\/|README\.md$)/.test(file.filename)) && !files.some(name => name.endsWith('.exe'))) {
        throw new Error('BLOCKED changed WinApp requires its hosted installer asset.');
    }
    const installers = files.filter(name => name.endsWith('.exe'));
    if (installers.length) {
        if (installers.length !== 1 || !files.includes('winapp-update.json')) throw new Error('BLOCKED incomplete companion installer pair.');
        const metadata = JSON.parse(readFileSync(resolve(directory,'winapp-update.json'),'utf8'));
        const installer = readFileSync(resolve(directory,installers[0]));
        const entry = metadata.assets?.find(asset => asset.name === installers[0]);
        if (!entry || entry.size !== installer.length || entry.sha256 !== createHash('sha256').update(installer).digest('hex')) {
            throw new Error('BLOCKED companion installer metadata does not match its bytes.');
        }
    }
    const assetHashes = Object.fromEntries(files.map(name => [name,createHash('sha256').update(readFileSync(resolve(directory,name))).digest('hex')]));
    const permanent = {...record, final_main_sha:main.sha, final_tree:main.commit.tree.sha, asset_sha256:assetHashes,
        actor:process.env.GITHUB_ACTOR,
        promotion_pr:{number:pr.number,url:pr.html_url,author:pr.user.login,
            reviewed_sha:request.candidate,approval:ownerReview,
            merge_commit_sha:pr.merge_commit_sha,merged_at:pr.merged_at},
        manual_review:request.manualReview, override:false,
        override_reason:request.reason, accepted_failures:failures,
        qualification_url:`https://github.com/${repository}/actions/runs/${request.runId}`,
        post_publication_host_smoke:'PENDING: no production installation credentials used'};
    writeFileSync(resolve(directory,'release-evidence.json'), JSON.stringify(permanent,null,2) + '\n');
    const tag = `v_${record.version}`;
    const tags = call(`repos/${repository}/git/matching-refs/tags/${tag}`);
    const existing = tags.find(ref => ref.ref === `refs/tags/${tag}`);
    if (existing && (existing.object.type !== 'commit' || existing.object.sha !== main.sha)) throw new Error('BLOCKED immutable tag collision.');
    if (!existing) call(`repos/${repository}/git/refs`, 'POST', {ref:`refs/tags/${tag}`,sha:main.sha});
    const releases = call(`repos/${repository}/releases?per_page=100`);
    let release = releases.find(item => item.tag_name === tag);
    if (!release) {
        execute('gh',['release','create',tag,'--repo',repository,'--verify-tag','--draft','--title',`PHP Gallery ${record.version}`,'--notes-file',resolve(directory,'release-notes.md')]);
        release = call(`repos/${repository}/releases/tags/${tag}`);
    }
    for (const name of readdirSync(directory).sort()) {
        const data = readFileSync(resolve(directory,name));
        const digest = 'sha256:' + createHash('sha256').update(data).digest('hex');
        const asset = release.assets.find(item => item.name === name);
        if (asset) {
            if (asset.digest !== digest || asset.size !== data.length) throw new Error(`BLOCKED existing release asset differs: ${name}`);
        } else {
            if (!release.draft) throw new Error('BLOCKED published release is missing immutable assets.');
            execute('gh',['release','upload',tag,resolve(directory,name),'--repo',repository]);
        }
    }
    // Recheck both refs after uploads; interrupted drafts remain recoverable, never public early.
    if (call(`repos/${repository}/git/ref/heads/main`).object.sha !== main.sha
        || call(`repos/${repository}/git/ref/heads/${request.branch}`).object.sha !== request.candidate
        || call(`repos/${repository}/git/ref/tags/${tag}`).object.sha !== main.sha) throw new Error('BLOCKED refs changed during publication.');
    if (release.draft) call(`repos/${repository}/releases/${release.id}`,'PATCH',{draft:false});
    const published = call(`repos/${repository}/releases/tags/${tag}`);
    if (published.draft || published.prerelease || published.tag_name !== tag
        || call(`repos/${repository}/git/ref/tags/${tag}`).object.sha !== main.sha) throw new Error('BLOCKED post-publication tag/release identity failed.');
    for (const name of readdirSync(directory)) {
        const asset = published.assets.find(item => item.name === name);
        const data = readFileSync(resolve(directory,name));
        if (!asset || asset.digest !== 'sha256:' + createHash('sha256').update(data).digest('hex') || asset.size !== data.length) throw new Error('BLOCKED post-publication asset verification failed.');
    }
    output('GITHUB_STEP_SUMMARY', `Published: ${published.html_url}\nFinal main: ${main.sha}\nProduction hosting smoke: pending`);
}

/** Inspect evidence or perform the separately approved release action on trusted main tooling.
 * @returns {Promise<void>} Reports a plan or completes the selected protected action.
 */
export async function main() {
    const request = readRequest();
    validateRequest(request);
    const repository = process.env.GITHUB_REPOSITORY;
    if (!/^[\w.-]+\/[\w.-]+$/.test(repository ?? '')) throw new Error('BLOCKED repository identity unavailable.');
    const run = api(`repos/${repository}/actions/runs/${request.runId}`);
    const jobs = fetchJobs(repository, request.runId, run.run_attempt);
    const record = JSON.parse(readFileSync(resolve(process.env.RELEASE_RECORD ?? 'release-record/release-candidate.json'),'utf8'));
    const current = api(`repos/${repository}/git/ref/heads/${request.branch}`).object.sha;
    const failures = verifyQualification(request,run,jobs,record,repository,current);
    output('GITHUB_OUTPUT', `version=${record.version}\nsource_base=${record.source_base}\nrun_attempt=${record.run_attempt}`);
    output('GITHUB_STEP_SUMMARY', `Qualification: https://github.com/${repository}/actions/runs/${request.runId}\nBranch: ${request.branch}\nCandidate: ${request.candidate}\nComparison: ${record.source_base}\nRequired jobs: ${jobs.length}\nOverride: ${request.override}\nHuman acceptance: ${request.manualReview || 'pending'}`);
    if (request.mode === 'plan') {
        if (process.env.RELEASE_INSPECTION_TARGET === 'publish') {
            const observed=inspectMergedPromotion(request,repository);
            output('GITHUB_STEP_SUMMARY','Merged bot PR: '+observed.pr.html_url
                + '\nEffective owner review: '+observed.ownerReview.id
                + '\nFinal verified main SHA: '+observed.main.sha);
        } else {
            requireCurrentMainBase(request,repository);
        }
        return;
    }
    if (request.mode !== 'publish') requireCurrentMainBase(request,repository);
    if (request.override) throw new Error('BLOCKED red-check override is unavailable for a single-owner release.');
    verifyWriteControls(repository);
    if (request.mode === 'promote') await promote(request,repository,failures);
    else await publish(request,repository,record,failures);
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
    main().catch(error => { process.stderr.write(error.message + '\n'); process.exitCode = 1; });
}
