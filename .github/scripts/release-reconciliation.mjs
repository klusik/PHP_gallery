/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-reconciliation.mjs
 * Module Type: Historical Release Reconciliation Validation
 * Purpose: Read retained owner-approved linear reconciliation and verify complete historical content.
 * Responsibilities:
 *   - Verify immutable published tag, evidence, ancestry and source identity
 *   - Report reconciliation only after owner acceptance, complete content proof and exact-SHA CI
 *   - Keep inspection read-only and refuse missing server controls
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {readFileSync,mkdtempSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {createHash} from 'node:crypto';
import {spawnSync} from 'node:child_process';
import {peelReleaseTag} from './release-origin.mjs';
import {api,command,workflowCoverageJobs} from './release-promotion.mjs';
import {requireOwnerRuleset,requireBotPullRequest,effectiveOwnerReview,qualificationCheckBinding} from './release-owner-authorization.mjs';

/** Recursive GitHub JSON transport value consumed by read-only reconciliation validators.
 * @typedef {null|boolean|number|string|JsonValue[]|{[key:string]:JsonValue}} JsonValue
 */
/** GitHub request adapter with optional method and JSON payload.
 * @typedef {function(string,string=,(JsonValue|null)=):JsonValue} GithubReader
 */
/** @typedef {{branch:string,mode:string,manualReview:string}} SyncRequest */
/** @typedef {function(string,string[]):string} GitReader */
/** @typedef {{path:string,base:string,develop:string,release:string,result:string,decision:string,review:string}} Resolution */
/** @typedef {{tagSha:string,candidateSha:string,branch:string,state:string,detail:string,developSha:string,mainSha:string,syncBranch:string,pr:number|null,qualificationRun:string|null,selectedDevelopSha:string,expectedTree?:string,conflicts?:string[],resultSha?:string}} SyncPlan */

/** Validate an exact release identity for read-only historical inspection.
 * @param {SyncRequest} request Exact release branch and read-only plan mode; manualReview is retained historical request metadata.
 * @returns {string} Canonical release version.
 */
export function validateSyncRequest(request) {
    const match = /^release\/v_((?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?)$/.exec(request.branch);
    if (!match || request.mode !== 'plan') {
        throw new Error('BLOCKED invalid release branch or reconciliation mode.');
    }
    return match[1];
}

/** Derive a working branch name bound to the release version and observed develop base.
 * @param {string} version Canonical version without tag prefix.
 * @param {string} developSha Exact observed develop SHA on which replay will be prepared.
 * @returns {string} Reconciliation branch name.
 */
export function syncBranchName(version, developSha) {
    if (!/^[a-f0-9]{40}$/.test(developSha)) throw new Error('BLOCKED invalid observed develop SHA.');
    return 'feature/reconcile-v_' + version + '-' + developSha.slice(0,12);
}

/** Fetch one immutable release-evidence asset and check GitHub's stored digest.
 * @param {string} repository Owner/repository.
 * @param {string} tag Immutable tag name.
 * @param {{name:string,digest:string,size:number}} asset Published GitHub asset metadata.
 * @returns {Record<string,unknown>} Parsed permanent release evidence.
 */
export function downloadReleaseEvidence(repository, tag, asset) {
    if (!asset || !['release-evidence.json','release-reconciliation.json'].includes(asset.name) || !/^sha256:[a-f0-9]{64}$/.test(asset.digest)) {
        throw new Error('BLOCKED immutable release-evidence asset digest is unavailable.');
    }
    const directory = mkdtempSync(join(tmpdir(),'gallery-release-sync-'));
    try {
        command('gh',['release','download',tag,'--repo',repository,'--pattern',asset.name,'--dir',directory]);
        const bytes = readFileSync(join(directory,asset.name));
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

/** Compute a complete three-way tree; conflicting paths require explicit owner decisions.
 * @param {string} selected Original selected develop SHA used as merge base.
 * @param {string} develop Exact parallel develop head.
 * @param {string} candidate Qualified release-side SHA.
 * @param {GitReader|undefined} git Optional inert Git reader.
 * @returns {{tree:string,conflicts:string[]}} Entire expected tree and every conflicted path.
 */
export function linearMergeTree(selected,develop,candidate,git) {
    if (![selected,develop,candidate].every(value=>/^[a-f0-9]{40}$/.test(value ?? ''))) {
        throw new Error('BLOCKED immutable three-way SHA inputs missing.');
    }
    const args = ['merge-tree','--write-tree','--merge-base='+selected,'--name-only','-z',develop,candidate];
    let stdout;
    if (git) stdout = git('git',args);
    else {
        const result = spawnSync('git',args,{encoding:'utf8',timeout:60000,maxBuffer:16*1024*1024});
        if (result.error || ![0,1].includes(result.status)) throw new Error('BLOCKED three-way Git comparison unavailable.');
        stdout = result.stdout;
    }
    const fields = stdout.split('\0');
    const tree = fields.shift().trim();
    if (!/^[a-f0-9]{40}$/.test(tree)) throw new Error('BLOCKED incomplete three-way tree.');
    const boundary = fields.indexOf('');
    if (boundary < 0) throw new Error('BLOCKED incomplete conflict path inventory.');
    return {tree,conflicts:fields.slice(0,boundary)};
}

/** Verify an SHA-preserving FF retains selected history and introduces no develop merge commits.
 * @param {string} selected Original release-selected develop SHA.
 * @param {string} develop Exact pre-FF develop SHA.
 * @param {string} candidate Qualified release-side Q used as the FF destination.
 * @param {GitReader|undefined} git Optional isolated Git reader.
 * @returns {void} Throws when ancestry is missing or the new develop range contains a merge commit.
 */
export function verifyFastForwardHistory(selected,develop,candidate,git=undefined) {
    if (![selected,develop,candidate].every(value=>/^[a-f0-9]{40}$/.test(value ?? ''))) {
        throw new Error('BLOCKED exact fast-forward history identities missing.');
    }
    const execute = git ?? command;
    if (execute('git',['merge-base',selected,develop])!==selected
        || execute('git',['merge-base',develop,candidate])!==develop
        || execute('git',['rev-list','--merges',develop+'..'+candidate]).trim()) {
        throw new Error('BLOCKED fast-forward must preserve develop history without new merge commits.');
    }
}

/** Verify a single-parent replay preserves the full merge result and all parallel history.
 * Regenerated runtime modules/inventory/manifest are independently freshness-checked by mandatory CI.
 * @param {string} selected Original release-selected develop SHA.
 * @param {string} develop Exact pre-FF develop SHA.
 * @param {string} candidate Exact released Q.
 * @param {string} result Exact proposed single-parent L.
 * @param {GitReader|undefined} git Optional isolated Git reader.
 * @param {Resolution[]} resolutions Explicit owner-reviewed decisions for every conflict.
 * @returns {{expected_tree:string,result_tree:string,release_patch_sha256:string,reconciliation_patch_sha256:string,resolutions:Resolution[],regenerated_paths:string[]}} Complete tree and byte-level patch evidence; throws for lost changes or unreviewed conflict.
 */
export function verifyLinearContent(selected,develop,candidate,result,git=undefined,resolutions=[]) {
    const execute = git ?? command;
    if (!/^[a-f0-9]{40}$/.test(result ?? '')) throw new Error('BLOCKED exact linear result SHA missing.');
    const parents = execute('git',['rev-list','--parents','-n','1',result]).split(/\s+/);
    if (parents.length !== 2 || parents[0] !== result || parents[1] !== develop) {
        throw new Error('BLOCKED equivalent reconciliation must have exactly the observed develop parent.');
    }
    if (execute('git',['rev-list','--merges',selected+'..'+develop]).trim()
        || execute('git',['merge-base',selected,candidate]) !== selected
        || execute('git',['merge-base',selected,develop]) !== selected) {
        throw new Error('BLOCKED parallel develop lost original selected history.');
    }
    const merge = linearMergeTree(selected,develop,candidate,git);
    const tree = execute('git',['rev-parse',result+'^{tree}']);
    const changed = execute('git',['diff','--name-only','--no-renames','-z',merge.tree,tree]).split('\0').filter(Boolean);
    const regenerated = ['app/runtime/modules.php','app/production-files.json','app/core-manifest.json'];
    if (!Array.isArray(resolutions) || new Set(resolutions.map(item=>item.path)).size !== resolutions.length
        || resolutions.length !== merge.conflicts.length
        || merge.conflicts.some(path=>!resolutions.some(item=>item.path===path))) {
        throw new Error('BLOCKED conflicts require explicit decisions for every affected path.');
    }
    for (const decision of resolutions) {
        if (!decision.decision?.trim() || !decision.review?.trim()) throw new Error('BLOCKED conflict decision/review missing.');
        for (const [field,sha] of [['base',selected],['develop',develop],['release',candidate],['result',result]]) {
            const entry = execute('git',['ls-tree','-z',sha,'--',decision.path]);
            if (decision[field] !== entry) throw new Error('BLOCKED conflict decision does not bind exact blob/mode/path.');
        }
    }
    if (changed.some(path=>!regenerated.includes(path) && !merge.conflicts.includes(path))) {
        throw new Error('BLOCKED full reconciled tree lost released or parallel changes.');
    }
    const digest = (base,head)=>{
        const args = ['diff','--binary','--full-index','--no-ext-diff','--no-renames',base,head];
        let bytes;
        if (git) bytes = git('git',args);
        else {
            const process = spawnSync('git',args,{timeout:60000,maxBuffer:16*1024*1024});
            if (process.error || process.status!==0) throw new Error('BLOCKED complete patch evidence unavailable.');
            bytes = process.stdout;
        }
        return createHash('sha256').update(bytes).digest('hex');
    };
    return {expected_tree:merge.tree,result_tree:tree,release_patch_sha256:digest(selected,candidate),
        reconciliation_patch_sha256:digest(develop,result),resolutions,
        regenerated_paths:changed.filter(path=>regenerated.includes(path))};
}

/** Find a genuine SHA-bound successful central candidate/release check and complete matrix.
 * @param {GithubReader} call GitHub read-only API.
 * @param {string} repository Exact repository.
 * @param {string} sha Result SHA that must be green before a protected FF.
 * @returns {string|null} Exact completed run URL or null for red/unqualified CI; unreadable or incomplete server evidence throws.
 */
export function qualifiedLinearCandidate(call,repository,sha) {
    const checks = call('repos/'+repository+'/commits/'+sha+'/check-runs?per_page=100&filter=all');
    if (!Array.isArray(checks?.check_runs) || checks.total_count >= 100
        || checks.total_count!==checks.check_runs.length) throw new Error('BLOCKED exact check inventory incomplete.');
    const candidates = checks.check_runs.filter(check=>['Candidate qualification','Release qualification'].includes(check.name)
        && check.head_sha===sha && check.app?.id===15368);
    for (const check of candidates) {
        const binding=qualificationCheckBinding(check.external_id);
        if (!binding || binding.sha!==sha || (binding.kind==='release')!==(check.name==='Release qualification')
            || check.status!=='completed' || check.conclusion!=='success') continue;
        const required=checks.check_runs.filter(value=>value.name==='Complete required CI matrix' && value.external_id===check.external_id);
        if (required.length!==1 || required[0].app?.id!==15368 || required[0].head_sha!==sha
            || required[0].status!=='completed' || required[0].conclusion!=='success') continue;
        const run = call('repos/'+repository+'/actions/runs/'+binding.runId);
        if (!['.github/workflows/candidate-preparation.yml','.github/workflows/release-qualification.yml',
            '.github/workflows/gallery-workflows.yml'].includes(run.path)
            || !['push','workflow_dispatch'].includes(run.event) || run.status !== 'completed'
            || run.conclusion !== 'success' || run.run_attempt!==binding.attempt
            || String(run.id)!==binding.runId
            || run.path!==(binding.kind==='release' ? '.github/workflows/release-qualification.yml' : '.github/workflows/candidate-preparation.yml')) continue;
        const listing = call('repos/'+repository+'/actions/runs/'+run.id+'/attempts/'+run.run_attempt+'/jobs?per_page=100');
        if (!Array.isArray(listing.jobs) || listing.total_count>=100 || listing.total_count!==listing.jobs.length) {
            throw new Error('BLOCKED historical job inventory incomplete.');
        }
        const coverage=workflowCoverageJobs(listing.jobs,repository,binding.runId,binding.attempt,sha,binding.kind,call);
        const requiredNames = ['Read-only generated-state and source preflight',
            'Positive production package (ubuntu-24.04)','Positive production package (windows-2025)',
            'Positive production package (macos-latest)','PHP 8.3 workflows (mysql:8.4, Unicode)',
            'PHP 8.3 workflows (mariadb:10.11, Unicode)','PHP 8.5 workflows (mariadb:11.4, Unicode)',
            'PHP 8.1 source (unicode)','PHP 8.5 source (ascii)','Required Chromium fixtures','Complete required CI matrix'];
        if (requiredNames.every(name=>coverage.filter(job=>(job.name===name || job.name.endsWith(' / '+name))
                && job.status==='completed' && job.conclusion==='success').length===1)) return 'https://github.com/'+repository+'/actions/runs/'+binding.runId;
    }
    return null;
}

/** Obtain a read-only, fail-closed reconciliation snapshot.
 * @param {SyncRequest} request Validated workflow request.
 * @param {string} repository Owner/repository.
 * @param {{api?:GithubReader,evidence?:Record<string,unknown>,reconciliation?:Record<string,unknown>,git?:GitReader}} adapters Optional hermetic fixture readers.
 * @returns {SyncPlan} Release state, SHA/tree identity and content/CI evidence.
 */
export function inspectSync(request, repository, adapters = {}) {
    const version = validateSyncRequest(request);
    const call = adapters.api ?? api;
    const identity = peelReleaseTag(call,repository,'v_'+version);
    const published = call('repos/'+repository+'/releases/tags/v_'+version);
    if (published.draft !== false || published.prerelease !== false || published.tag_name !== 'v_'+version) {
        throw new Error('BLOCKED release is not published at the exact immutable tag.');
    }
    const asset = published.assets?.find(item=>item.name==='release-evidence.json');
    if (!asset) throw new Error('BLOCKED permanent release evidence missing.');
    const evidence = adapters.evidence ?? downloadReleaseEvidence(repository,'v_'+version,asset);
    const commit = call('repos/'+repository+'/commits/'+identity.commit_sha);
    const candidate = call('repos/'+repository+'/commits/'+evidence.candidate_sha);
    if (evidence.repository !== repository || evidence.final_main_sha !== identity.commit_sha
        || evidence.final_tree !== identity.tree_sha || candidate.commit?.tree?.sha !== identity.tree_sha
        || evidence.version !== version || evidence.branch !== request.branch
        || commit.parents?.length !== 2 || commit.parents[0]?.sha !== evidence.initial_main_sha
        || commit.parents[1]?.sha !== evidence.candidate_sha || evidence.override !== false
        || evidence.ready !== true || evidence.automated_result !== 'success') {
        throw new Error('BLOCKED released parents/tree and durable qualification evidence disagree.');
    }
    const developSha = call('repos/'+repository+'/git/ref/heads/develop').object?.sha;
    const mainSha = call('repos/'+repository+'/git/ref/heads/main').object?.sha;
    if (!isAncestor(call,repository,identity.commit_sha,mainSha)) throw new Error('BLOCKED published main history lost.');
    const plan = {tagSha:identity.commit_sha,candidateSha:evidence.candidate_sha,branch:request.branch,
        developSha,mainSha,syncBranch:syncBranchName(version,developSha),pr:null,qualificationRun:null,
        selectedDevelopSha:evidence.selected_develop_sha,state:'SYNC_PENDING',detail:'Owner-approved SHA-preserving FF remains pending.'};
    if (!developProtected(call,repository)) return {...plan,state:'SYNC_BLOCKED',detail:'Develop requires active linear non-force full-CI protection without mandatory PR.'};
    const auditAsset = published.assets.find(item=>item.name==='release-reconciliation.json');
    const proof = adapters.reconciliation ?? (auditAsset ? downloadReleaseEvidence(repository,'v_'+version,auditAsset) : null);
    if (!proof) {
        if (isAncestor(call,repository,developSha,evidence.candidate_sha)) {
            verifyFastForwardHistory(evidence.selected_develop_sha,developSha,evidence.candidate_sha,adapters.git);
            return {...plan,detail:'Develop can fast-forward to exact qualified Q; no merge commit or main ancestry is needed.'};
        }
        const merge = linearMergeTree(evidence.selected_develop_sha,developSha,evidence.candidate_sha,adapters.git);
        return {...plan,state:merge.conflicts.length ? 'SYNC_BLOCKED':'SYNC_PENDING',expectedTree:merge.tree,
            conflicts:merge.conflicts,detail:merge.conflicts.length ? 'Explicit owner conflict decisions required.'
                : 'Prepare one single-parent replay on current develop, qualify it, then request owner FF.'};
    }
    if (proof.repository !== repository || proof.candidate_sha !== evidence.candidate_sha
        || proof.published_main_sha !== identity.commit_sha || proof.selected_develop_sha !== evidence.selected_develop_sha
        || !['RECONCILED_FF','RECONCILED_EQUIVALENT'].includes(proof.state)) {
        return {...plan,state:'SYNC_BLOCKED',detail:'Immutable reconciliation proof identities differ.'};
    }
    const ownerRun = call('repos/'+repository+'/actions/runs/'+proof.owner_run_id+'/attempts/'+proof.owner_run_attempt);
    if (ownerRun.path !== '.github/workflows/release-reconciliation.yml' || ownerRun.event !== 'workflow_dispatch'
        || ownerRun.head_branch !== 'main' || ownerRun.actor?.login !== repository.split('/')[0]
        || ownerRun.run_attempt !== Number(proof.owner_run_attempt) || ownerRun.status !== 'completed' || ownerRun.conclusion !== 'success') {
        return {...plan,state:'SYNC_PENDING',detail:'Owner reconciliation run is not completed successfully.'};
    }
    if (!isAncestor(call,repository,proof.develop_base_sha,proof.result_sha)
        || !isAncestor(call,repository,proof.result_sha,developSha)) {
        return {...plan,state:'SYNC_BLOCKED',detail:'Result or previous develop history is absent from current develop.'};
    }
    if (proof.state==='RECONCILED_FF') {
        if (proof.result_sha!==evidence.candidate_sha || proof.result_tree!==identity.tree_sha) {
            return {...plan,state:'SYNC_BLOCKED',detail:'FF proof did not preserve exact release Q/tree.'};
        }
        verifyFastForwardHistory(proof.selected_develop_sha,proof.develop_base_sha,proof.result_sha,adapters.git);
    } else {
        const content = verifyLinearContent(proof.selected_develop_sha,proof.develop_base_sha,
            proof.candidate_sha,proof.result_sha,adapters.git,proof.resolutions ?? []);
        for (const [key,value] of Object.entries(content)) {
            if (JSON.stringify(proof[key])!==JSON.stringify(value)) return {...plan,state:'SYNC_BLOCKED',detail:'Reconciliation full-tree/patch evidence differs.'};
        }
    }
    const ci = qualifiedLinearCandidate(call,repository,proof.result_sha);
    if (!ci || ci!==proof.qualification_url) return {...plan,state:'SYNC_PENDING',detail:'Exact result GitHub CI is missing, red or stale.'};
    if (call('repos/'+repository+'/git/ref/heads/develop').object?.sha!==developSha) {
        return {...plan,state:'SYNC_BLOCKED',detail:'Develop raced during verification; re-plan.'};
    }
    return {...plan,state:proof.state,resultSha:proof.result_sha,qualificationRun:ci,
        detail:'Owner-approved linear reconciliation, full content proof and exact hosted CI verified.'};
}
