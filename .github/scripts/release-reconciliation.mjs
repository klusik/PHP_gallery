/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-reconciliation.mjs
 * Module Type: Protected Release Reconciliation
 * Purpose: Reconcile a published main release into develop only through reviewed, qualified GitHub PRs.
 * Responsibilities:
 *   - Verify immutable published tag, evidence, ancestry and source identity
 *   - Propose a dedicated exact-tag sync branch without modifying protected refs
 *   - Report reconciliation only after a reviewed PR and exact-merge-sha hosted CI
 *   - Keep inspection read-only and refuse missing server controls
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {readFileSync,mkdtempSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {createHash} from 'node:crypto';
import {pathToFileURL} from 'node:url';
import {resolve} from 'node:path';
import {api,command,output} from './release-promotion.mjs';
import {requireOwnerDispatch,requireOwnerEnvironment,requireOwnerRuleset,requireBotPullRequest,effectiveOwnerReview} from './release-owner-authorization.mjs';

/** Recursive GitHub JSON transport value consumed by read-only reconciliation validators.
 * @typedef {null|boolean|number|string|JsonValue[]|{[key:string]:JsonValue}} JsonValue
 */
/** GitHub request adapter with optional method and JSON payload.
 * @typedef {function(string,string=,(JsonValue|null)=):JsonValue} GithubReader
 */
/** @typedef {{branch:string,mode:string,manualReview:string}} SyncRequest */
/** @typedef {{tagSha:string,candidateSha:string,branch:string,state:string,detail:string,developSha:string,mainSha:string,syncBranch:string,pr:number|null,qualificationRun:string|null}} SyncPlan */

/** Validate the requested release identity and requested level of authority.
 * @param {SyncRequest} request Explicit workflow dispatch fields.
 * @returns {string} Canonical release version.
 */
export function validateSyncRequest(request) {
    const match = /^release\/v_((?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?)$/.exec(request.branch);
    if (!match || !['plan','propose','verify'].includes(request.mode)) {
        throw new Error('BLOCKED invalid release branch or reconciliation mode.');
    }
    if (request.mode === 'propose' && !request.manualReview.trim()) {
        throw new Error('BLOCKED sync proposal requires explicit maintainer acceptance evidence.');
    }
    return match[1];
}

/** Derive a branch name that is unique per published tag and immutable commit.
 * @param {string} version Canonical version without tag prefix.
 * @param {string} tagSha Published immutable main SHA.
 * @returns {string} Reconciliation branch name.
 */
export function syncBranchName(version, tagSha) {
    if (!/^[a-f0-9]{40}$/.test(tagSha)) throw new Error('BLOCKED invalid published main SHA.');
    return 'sync/release/v_' + version + '-' + tagSha.slice(0,12);
}

/** Fetch one immutable release-evidence asset and check GitHub's stored digest.
 * @param {string} repository Owner/repository.
 * @param {string} tag Immutable tag name.
 * @param {{name:string,digest:string,size:number}} asset Published GitHub asset metadata.
 * @returns {Record<string,unknown>} Parsed permanent release evidence.
 */
export function downloadReleaseEvidence(repository, tag, asset) {
    if (asset.name !== 'release-evidence.json' || !/^sha256:[a-f0-9]{64}$/.test(asset.digest)) {
        throw new Error('BLOCKED immutable release-evidence asset digest is unavailable.');
    }
    const directory = mkdtempSync(join(tmpdir(),'gallery-release-sync-'));
    try {
        command('gh',['release','download',tag,'--repo',repository,'--pattern','release-evidence.json','--dir',directory]);
        const bytes = readFileSync(join(directory,'release-evidence.json'));
        if (bytes.length !== asset.size || 'sha256:' + createHash('sha256').update(bytes).digest('hex') !== asset.digest) {
            throw new Error('BLOCKED downloaded release evidence differs from the published asset.');
        }
        return JSON.parse(bytes.toString('utf8'));
    } finally {
        rmSync(directory,{recursive:true,force:true});
    }
}

/** Refuse fabricated ancestry or truncated API identity.
 * @param {GithubReader} call GitHub API reader.
 * @param {string} repository Owner/repository.
 * @param {string} ancestor Exact expected ancestor SHA.
 * @param {string} descendant Exact expected descendant SHA.
 * @returns {boolean} True only when the first SHA is an ancestor of the second.
 */
export function isAncestor(call, repository, ancestor, descendant) {
    if (!/^[a-f0-9]{40}$/.test(ancestor) || !/^[a-f0-9]{40}$/.test(descendant)) {
        throw new Error('BLOCKED malformed Git ancestry input.');
    }
    if (ancestor === descendant) return true;
    const comparison = call('repos/' + repository + '/compare/' + ancestor + '...' + descendant);
    if (!Number.isInteger(comparison?.behind_by) || comparison.behind_by < 0) {
        throw new Error('BLOCKED GitHub ancestry result missing or invalid.');
    }
    return comparison.behind_by === 0;
}

/** Resolve an exact matching branch reference without prefix ambiguity.
 * @param {GithubReader} call GitHub API reader.
 * @param {string} repository Owner/repository.
 * @param {string} branch Validated branch identity.
 * @returns {string|null} Existing exact ref commit SHA, or null if absent.
 */
export function matchingBranchSha(call, repository, branch) {
    const refs = call('repos/' + repository + '/git/matching-refs/heads/' + branch);
    if (!Array.isArray(refs)) throw new Error('BLOCKED cannot enumerate release synchronization refs.');
    const found = refs.find(ref => ref.ref === 'refs/heads/' + branch);
    if (!found) return null;
    if (found.object?.type !== 'commit' || !/^[a-f0-9]{40}$/.test(found.object.sha)) {
        throw new Error('BLOCKED unexpected ref target.');
    }
    return found.object.sha;
}

/** Require live owner-approved develop protections before reconciliation proceeds.
 * @param {GithubReader} call GitHub API reader.
 * @param {string} repository Expected owner/name.
 * @returns {boolean} True only when the installed ruleset and effective rules agree.
 */
export function developProtected(call, repository) {
    try {
        requireOwnerRuleset(call,repository,'develop',
            'Reviewed main-to-develop release reconciliation','Complete required CI matrix');
        return true;
    } catch {
        return false;
    }
}

/** Find exact develop-push GitHub Actions evidence for the merged reconciliation commit.
 * @param {GithubReader} call GitHub API reader.
 * @param {string} repository Owner/repository.
 * @param {string} sha Merge commit produced by the reviewed synchronization PR.
 * @returns {string|null} Successful run URL, or null when evidence is missing.
 */
export function qualifiedDevelopMerge(call, repository, sha) {
    const listing = call('repos/' + repository + '/actions/workflows/gallery-workflows.yml/runs?branch=develop&event=push&head_sha=' + sha + '&per_page=100');
    if (!Array.isArray(listing?.workflow_runs) || listing.total_count > 100) {
        throw new Error('BLOCKED incomplete develop qualification run listing.');
    }
    const runs = listing.workflow_runs.filter(run =>
        run.path === '.github/workflows/gallery-workflows.yml'
        && run.head_branch === 'develop' && run.head_sha === sha
        && run.event === 'push' && run.status === 'completed' && run.conclusion === 'success');
    for (const run of runs) {
        if (!Number.isInteger(run.run_attempt) || run.run_attempt < 1) continue;
        const jobs = call('repos/' + repository + '/actions/runs/' + run.id + '/attempts/' + run.run_attempt + '/jobs?per_page=100');
        if (!Array.isArray(jobs?.jobs) || jobs.total_count > 100) continue;
        // Match the authoritative full-profile ownership, excluding only
        // the release-only audit intentionally skipped by ordinary develop CI.
        const mandatory = [
            'Read-only generated-state and source preflight',
            'Positive production package (ubuntu-24.04)',
            'Positive production package (windows-2025)',
            'Positive production package (macos-latest)',
            'PHP 8.3 workflows (mysql:8.4, Unicode)',
            'PHP 8.3 workflows (mariadb:10.11, Unicode)',
            'PHP 8.5 workflows (mariadb:11.4, Unicode)',
            'PHP 8.1 source (unicode)',
            'PHP 8.5 source (ascii)',
            'Required Chromium fixtures',
            'Complete required CI matrix',
        ];
        if (mandatory.every(name => jobs.jobs.filter(job => job.name === name
            && job.status === 'completed' && job.conclusion === 'success').length === 1)) {
            return run.html_url;
        }
    }
    return null;
}

/** Decode only an exact SHA-scoped maintained release document from GitHub.
 * @param {GithubReader} call GitHub read-only API adapter.
 * @param {string} repository Expected owner/repository identity.
 * @param {string} sha Immutable published or merged commit SHA.
 * @param {string} path Canonical release-metadata.json or PATCH_NOTES.md path.
 * @returns {string} Complete, server-owned UTF-8 document contents.
 */
export function readPinnedReleaseDocument(call,repository,sha,path) {
    if (!/^[a-f0-9]{40}$/.test(sha) || !['release-metadata.json','PATCH_NOTES.md'].includes(path)) {
        throw new Error('BLOCKED release document must have an immutable SHA and allowlisted path.');
    }
    const response=call('repos/'+repository+'/contents/'+path+'?ref='+sha);
    if (response?.type !== 'file' || response.encoding !== 'base64'
        || !Number.isInteger(response.size) || response.size < 1
        || typeof response.content !== 'string') {
        throw new Error('BLOCKED server could not return an intact SHA-scoped release document.');
    }
    const base64=response.content.replace(/\s/g,'');
    if (!/^[A-Za-z0-9+/]+={0,2}$/.test(base64)) {
        throw new Error('BLOCKED malformed GitHub release document encoding.');
    }
    const bytes=Buffer.from(base64,'base64');
    if (bytes.length !== response.size || bytes.toString('base64') !== base64) {
        throw new Error('BLOCKED incomplete GitHub release document bytes.');
    }
    return bytes.toString('utf8');
}

/** Verify immutable release metadata and editorial notes survive the reviewed merge.
 * @param {GithubReader} call GitHub read-only API adapter.
 * @param {string} repository Expected owner/repository identity.
 * @param {string} version Exact published numeric version.
 * @param {string} tagSha Immutable published-main commit.
 * @param {string} mergeSha Exact reviewed develop merge commit.
 * @returns {boolean} True only if historical release metadata and notes are still identical.
 */
export function publishedContentSurvived(call,repository,version,tagSha,mergeSha) {
    const metadata = sha => {
        const value=JSON.parse(readPinnedReleaseDocument(call,repository,sha,'release-metadata.json'))[version];
        if (!value || value.tag !== 'v_'+version
            || typeof value.released_at !== 'string' || !value.released_at
            || typeof value.released_label !== 'string' || !value.released_label) {
            throw new Error('BLOCKED missing published version metadata in reconciled commit.');
        }
        return JSON.stringify([value.released_at,value.released_label,value.tag]);
    };
    const notes = sha => {
        const text=readPinnedReleaseDocument(call,repository,sha,'PATCH_NOTES.md').replace(/\r\n/g,'\n');
        const header='## Version '+version;
        const matches=[...text.matchAll(/^## Version[^\n]*$/gm)].filter(match => match[0].trim()===header);
        if (matches.length !== 1) throw new Error('BLOCKED published patch-notes version heading missing or ambiguous.');
        const start=matches[0].index;
        const end=text.indexOf('\n## Version ',start+header.length);
        return text.slice(start,end === -1 ? text.length : end).trimEnd();
    };
    return metadata(tagSha)===metadata(mergeSha) && notes(tagSha)===notes(mergeSha);
}

/** Accept only the final SHA-bound review by the single repository owner.
 * @param {{id:number,user:{login:string},state:string,commit_id:string,submitted_at:string,html_url?:string}[]} reviews Complete PR review history, not truncated.
 * @param {{user:{login:string},merged_at:string|null,created_at?:string}} pr Server-reported merged pull request authored by the bot.
 * @param {string} owner Expected human repository owner.
 * @param {string} sha Exact immutable published head.
 * @returns {{id:number,reviewer:string,commit_sha:string,submitted_at:string,url:string|null}|null} Verified owner review, or null when unavailable.
 */
export function hasEffectiveSyncApproval(reviews,pr,owner,sha) {
    return effectiveOwnerReview(reviews,pr,owner,sha);
}

/** Obtain a read-only, fail-closed reconciliation snapshot.
 * @param {SyncRequest} request Validated workflow request.
 * @param {string} repository Owner/repository.
 * @param {{api?:GithubReader,evidence?:Record<string,unknown>}} adapters Optional hermetic fixture readers.
 * @returns {SyncPlan} Release state and its most relevant evidence.
 */
export function inspectSync(request, repository, adapters = {}) {
    const version = validateSyncRequest(request);
    const call = adapters.api ?? api;
    const tag = 'v_' + version;
    const tagRef = call('repos/' + repository + '/git/ref/tags/' + tag);
    if (tagRef.object?.type !== 'commit' || !/^[a-f0-9]{40}$/.test(tagRef.object.sha)) {
        throw new Error('BLOCKED expected immutable release commit tag is missing.');
    }
    const tagSha = tagRef.object.sha;
    const published = call('repos/' + repository + '/releases/tags/' + tag);
    if (published.tag_name !== tag || published.draft !== false || published.prerelease === true) {
        throw new Error('BLOCKED release is not published at the exact immutable tag.');
    }
    const evidenceAsset = published.assets?.find(asset => asset.name === 'release-evidence.json');
    if (!evidenceAsset) throw new Error('BLOCKED permanent release evidence asset missing.');
    const evidence = adapters.evidence ?? downloadReleaseEvidence(repository,tag,evidenceAsset);
    const commit = call('repos/' + repository + '/commits/' + tagSha);
    if (evidence.final_main_sha !== tagSha || evidence.final_tree !== commit.commit?.tree?.sha
        || evidence.version !== version || evidence.branch !== request.branch
        || !/^[a-f0-9]{40}$/.test(evidence.candidate_sha ?? '')
        || !/^[1-9]\d*$/.test(evidence.run_id ?? '')) {
        throw new Error('BLOCKED released commit and durable qualification evidence disagree.');
    }
    const mainSha = call('repos/' + repository + '/git/ref/heads/main').object?.sha;
    const developSha = call('repos/' + repository + '/git/ref/heads/develop').object?.sha;
    if (!isAncestor(call,repository,tagSha,mainSha)) {
        throw new Error('BLOCKED published main ancestry was rewritten or missing.');
    }
    const branch = syncBranchName(version,tagSha);
    const branchSha = matchingBranchSha(call,repository,branch);
    if (branchSha && !isAncestor(call,repository,tagSha,branchSha)) {
        throw new Error('BLOCKED synchronization branch lost immutable main ancestry.');
    }
    const owner = repository.split('/')[0];
    const pulls = call('repos/' + repository + '/pulls?state=all&base=develop&head='
        + encodeURIComponent(owner + ':' + branch) + '&per_page=100');
    if (!Array.isArray(pulls) || pulls.length >= 100) {
        throw new Error('BLOCKED ambiguous or truncated reconciliation PR listing.');
    }
    const matching = pulls.filter(pr => pr.head?.ref === branch && pr.head?.repo?.full_name === repository
        && pr.base?.ref === 'develop');
    if (matching.length > 1) throw new Error('BLOCKED multiple reconciliation PRs for one immutable release.');
    const pr = matching[0] ?? null;
    const protectedBranch = developProtected(call,repository);
    const baseline = {tagSha,candidateSha:evidence.candidate_sha,branch:request.branch,developSha,mainSha,syncBranch:branch,pr:pr?.number ?? null,
        qualificationRun:null,state:'SYNC_PENDING',detail:'Published release has no completed reviewed reconciliation.'};
    if (!protectedBranch) {
        return {...baseline,state:'SYNC_BLOCKED',detail:'Develop is missing active owner-review ruleset or effective strict full-CI protection.'};
    }
    if (pr) {
        try {
            requireBotPullRequest(pr,repository,tagSha,'develop');
        } catch (error) {
            return {...baseline,state:'SYNC_BLOCKED',detail:error.message};
        }
    }
    if (pr && branchSha && pr.head.sha !== branchSha && !pr.merged_at) {
        return {...baseline,state:'SYNC_BLOCKED',detail:'Open reconciliation PR head no longer matches the dedicated branch.'};
    }
    if (pr?.merged_at) {
        if (!/^[a-f0-9]{40}$/.test(pr.merge_commit_sha ?? '')
            || !isAncestor(call,repository,tagSha,developSha)
            || !isAncestor(call,repository,pr.merge_commit_sha,developSha)) {
            return {...baseline,state:'SYNC_BLOCKED',detail:'Merged reconciliation PR is not included in current develop ancestry.'};
        }
        const merge = call('repos/' + repository + '/commits/' + pr.merge_commit_sha);
        if (merge.parents?.length !== 2 || merge.parents[1]?.sha !== tagSha
            || !/^[a-f0-9]{40}$/.test(merge.parents[0]?.sha ?? '')
            || !isAncestor(call,repository,merge.parents[0].sha,developSha)) {
            return {...baseline,state:'SYNC_BLOCKED',detail:'Reconciliation was not a full merge retaining both develop and published main parents.'};
        }
        const reviews = call('repos/' + repository + '/pulls/' + pr.number + '/reviews?per_page=100');
        const approval=hasEffectiveSyncApproval(reviews,pr,owner,tagSha);
        if (!approval) {
            return {...baseline,state:'SYNC_BLOCKED',detail:'No current owner SHA-bound PR approval exists for the exact published merge parent.'};
        }
        try {
            if (!publishedContentSurvived(call,repository,version,tagSha,pr.merge_commit_sha)) {
                return {...baseline,state:'SYNC_BLOCKED',detail:'Published release notes or version metadata were lost or rewritten by reconciliation.'};
            }
        } catch (error) {
            return {...baseline,state:'SYNC_BLOCKED',detail:'Published release content could not be verified: '+error.message};
        }
        const success = qualifiedDevelopMerge(call,repository,pr.merge_commit_sha);
        return {...baseline,state:success ? 'RECONCILED' : 'SYNC_PENDING',
            detail:success ? 'Published main ancestry and exact merged develop SHA passed hosted CI.' : 'Merged PR awaits exact-SHA hosted develop push qualification.',
            qualificationRun:success,ownerApproval:approval};
    }
    if (isAncestor(call,repository,tagSha,developSha)) {
        return {...baseline,state:'SYNC_BLOCKED',detail:'Published ancestry is present without an auditable merged reconciliation PR.'};
    }
    if (pr && pr.state !== 'open') {
        return {...baseline,state:'SYNC_BLOCKED',detail:'Reconciliation PR was closed without a merge.'};
    }
    return {...baseline,detail:pr ? 'Reviewed reconciliation PR is open; merge and CI remain pending.' : 'A reconciliation PR has not yet been proposed.'};
}

/** Require the owner-approved GitHub Environment and active develop PR/CI rules.
 * @param {string} repository Expected owner/name.
 * @param {GithubReader} call GitHub API reader.
 * @param {Record<string,string|undefined>} context Trusted workflow context.
 * @returns {void} Throws instead of allowing unapproved or unprotected writes.
 */
export function verifySyncWriteControls(repository, call, context = process.env) {
    requireOwnerDispatch(repository,call,context);
    requireOwnerEnvironment(call,repository,'release-reconciliation');
    requireOwnerRuleset(call,repository,'develop',
        'Reviewed main-to-develop release reconciliation','Complete required CI matrix');
}

/** Propose one immutable-tag synchronization branch and reviewed PR, without merging.
 * @param {SyncRequest} request Reviewed propose-mode dispatch.
 * @param {string} repository Owner/repository.
 * @param {{api?:GithubReader,evidence?:Record<string,unknown>,context?:Record<string,string|undefined>}} adapters Optional isolated fixtures.
 * @returns {SyncPlan} Pending synchronization state, never an invented completed result.
 */
export function proposeSync(request, repository, adapters = {}) {
    if (request.mode !== 'propose') throw new Error('BLOCKED proposeSync requires propose mode.');
    const call = adapters.api ?? api;
    const snapshot = inspectSync(request,repository,adapters);
    if (snapshot.state === 'RECONCILED') return snapshot;
    if (snapshot.state === 'SYNC_BLOCKED') throw new Error('BLOCKED ' + snapshot.detail);
    verifySyncWriteControls(repository,call,adapters.context ?? process.env);
    const current = matchingBranchSha(call,repository,snapshot.syncBranch);
    if (current && current !== snapshot.tagSha) {
        throw new Error('BLOCKED existing synchronization branch has different content; maintainer review required.');
    }
    if (!current) {
        call('repos/' + repository + '/git/refs','POST',{ref:'refs/heads/' + snapshot.syncBranch,sha:snapshot.tagSha});
    }
    if (snapshot.pr === null) {
        const body = 'Post-publication reconciliation for ' + request.branch + '\n'
            + 'Immutable published main SHA: ' + snapshot.tagSha + '\n'
            + 'Develop starting SHA: ' + snapshot.developSha + '\n'
            + 'Owner dispatch acceptance: ' + request.manualReview + '\n'
            + 'Required: owner PR approval, manual merge, and full hosted develop CI.\n'
            + 'PR CI may require Approve workflows to run by the repository owner.\n'
            + 'No direct develop update, merge automation or bypass.\n'
            + 'Refs #101 #136 #137';
        const pr = call('repos/' + repository + '/pulls','POST',{
            title:'Sync published release v_' + validateSyncRequest(request) + ' into develop',
            head:snapshot.syncBranch,base:'develop',body,
            maintainer_can_modify:false});
        requireBotPullRequest(pr,repository,snapshot.tagSha,'develop');
    }
    if (call('repos/' + repository + '/git/ref/heads/develop').object?.sha !== snapshot.developSha) {
        throw new Error('BLOCKED develop advanced during sync proposal; re-plan before approval.');
    }
    return {...snapshot,state:'SYNC_PENDING',detail:'Dedicated sync PR awaits independent review, full CI and merge.'};
}

/** Run read-only status inspection or the separately reviewed proposal.
 * @returns {Promise<void>} Writes only GitHub step summaries, or an approved sync PR.
 */
export async function main() {
    const request = {branch:process.env.RELEASE_BRANCH ?? '', mode:process.env.SYNC_MODE ?? 'plan',
        manualReview:process.env.MANUAL_REVIEW ?? ''};
    validateSyncRequest(request);
    const repository = process.env.GITHUB_REPOSITORY ?? '';
    if (!/^[\w.-]+\/[\w.-]+$/.test(repository)
        || process.env.GITHUB_EVENT_NAME !== 'workflow_dispatch' || process.env.GITHUB_REF !== 'refs/heads/main') {
        throw new Error('BLOCKED reconciliation inspection requires trusted main workflow dispatch.');
    }
    const plan = request.mode === 'propose' ? proposeSync(request,repository) : inspectSync(request,repository);
    output('GITHUB_STEP_SUMMARY','Release reconciliation: ' + plan.state + '\n'
        + 'Published main: ' + plan.tagSha + '\nDevelop: ' + plan.developSha + '\n'
        + 'Sync PR: ' + (plan.pr ?? 'none') + '\n' + plan.detail + '\n'
        + 'Exact CI: ' + (plan.qualificationRun ?? 'PENDING'));
    process.stdout.write(JSON.stringify(plan,null,2) + '\n');
}
if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
    main().catch(error => {process.stderr.write(error.message + '\n');process.exitCode = 1;});
}
