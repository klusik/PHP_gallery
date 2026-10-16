/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-origin.mjs
 * Module Type: Release Origin Verification
 * Purpose: Bind every newly qualified release to a reviewed, immutable develop/main/tag ancestry anchor.
 * Responsibilities:
 *   - Verify an explicit operator-selected develop commit, not the moving develop head
 *   - Require initial origin metadata to be introduced directly on the selected develop SHA
 *   - Preserve the original release context across release stabilization and reruns
 *   - Refuse partial or contradictory branch, tag and main history evidence
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {api,command,output,verifyQualification,fetchJobs,requireQualificationChecks} from './release-promotion.mjs';
import {loadQualification,qualificationRequest} from './release-completion.mjs';
import {requireBotPullRequest,effectiveOwnerReview} from './release-owner-authorization.mjs';
import {isAncestor,downloadReleaseEvidence,verifyLinearContent,qualifiedLinearCandidate} from './release-reconciliation.mjs';
import {loadReleaseCoverage,readCoverageArtifact} from './coverage-artifacts.mjs';
import {fullMatrixOwners} from './ci-owner-contract.mjs';
import {verifyMaintenanceLineage,verifyMaintenanceMerge,MAINTENANCE_READ_LIMIT} from './maintenance-authority.mjs';

/** Bind the sole untagged main repair approved before release 0.132.9.
 * Type: Readonly<{repository:string,version:string,main:string,stable:string,tag:string,repair:string,tree:string,transport:string,run:string,attempt:number,artifact:number,digest:string,pr:number,review:number,check:number}>.
 * Units: immutable Git, Actions, artifact and PR identities. Scope: release 0.132.9 only.
 * Consumers: origin initialization, qualification and protected promotion readers.
 * Rationale: the reviewed dispatcher repair advanced main without moving the existing stable tag; arbitrary untagged main commits remain ineligible.
 */
export const MAINTENANCE_MAIN_BRIDGE=Object.freeze({repository:'klusik/PHP_gallery',version:'0.132.9',
    main:'66cbe28d345f27ca90827028381e1a458e6f89f9',stable:'d38443c355a2b2e14cbfaa1757e9fde4448f8fbb',tag:'v_0.132.8',
    repair:'6aa5e3a8ccd4cd70f3f49ea83c9906a4086c1862',tree:'45cec56401a1b88c62b10f2d2f3a0d617b631dab',
    transport:'6d087733e59175cbc5197d72f490ad8e2fb9e485',run:'38801060282',attempt:2,artifact:12005310526,
    digest:'sha256:19c224811fab989bbc26a26044f2370ee0cb0eaeceae3b12e506b8946aa22cbe',pr:230,review:5539494509,check:116654815797});

/** Recognize the exact finite maintenance main/stable identity without claiming authentication.
 * @param {{version:string,initial_main_sha:string,previous_stable_sha:string,previous_stable_tag:string}} record Origin or release record projection.
 * @returns {boolean} True only for the exact approved 0.132.9 bridge tuple; provider authentication is separate.
 */
export function isMaintenanceMainBridge(record) {
    const pin=MAINTENANCE_MAIN_BRIDGE;
    return record?.version===pin.version && record.initial_main_sha===pin.main
        && record.previous_stable_sha===pin.stable && record.previous_stable_tag===pin.tag;
}

/** Select the stable predecessor without treating arbitrary untagged main commits as releases.
 * @param {string} version Intended canonical release version.
 * @param {string} main Observed current main commit.
 * @param {string} repository Exact owner/repository.
 * @param {import('./coverage-artifacts.mjs').ArtifactAdapters & {stableTags?:Array<{tag:string,sha:string}>}} adapters Provider/archive readers and the complete canonical stable tag inventory when selecting a new origin.
 * @returns {string} Existing stable tag commit reached through protected main's bounded first-parent history; historical finite origins retain their reader.
 */
export function releaseStableMain(version,main,repository,adapters={}) {
    const pin=MAINTENANCE_MAIN_BRIDGE;
    const record={version,initial_main_sha:main,previous_stable_sha:pin.stable,previous_stable_tag:pin.tag};
    if (isMaintenanceMainBridge(record)) {
        verifyMaintenanceMainBridge(record,repository,adapters);
        return pin.stable;
    }
    if (!Array.isArray(adapters.stableTags)) return main;
    const tagged=new Set(adapters.stableTags.map(tag=>tag.sha));
    let current=main;
    // The bound is shared with the authenticated reader; every untagged step must be a reviewed maintenance merge.
    for (let depth=0;depth<MAINTENANCE_READ_LIMIT;depth++) {
        if (tagged.has(current)) return current;
        current=verifyMaintenanceMerge(repository,current,{...adapters,api:adapters.api ?? api});
    }
    throw new Error('BLOCKED maintenance predecessor discovery exceeds its bound.');
}

/** Preserve stable tag/main ancestry, retaining independently pinned qualification only for historical finite origins.
 * @param {{version:string,initial_main_sha:string,previous_stable_sha:string,previous_stable_tag:string}} record Checked origin or qualification projection; ordinary equal main/stable needs no exception.
 * @param {string} repository Exact owner/repository identity.
 * @param {import('./coverage-artifacts.mjs').ArtifactAdapters} adapters Independent provider and complete raw-archive readers; production defaults remain read-only.
 * @returns {void} Returns for unchanged main/stable or actual protected first-parent lineage; the historical finite origin keeps its original proof rules. Never writes a ref, tag or check.
 */
export function verifyMaintenanceMainBridge(record,repository,adapters={}) {
    if (record.initial_main_sha===record.previous_stable_sha) return;
    const pin=MAINTENANCE_MAIN_BRIDGE,call=adapters.api ?? api;
    if (!isMaintenanceMainBridge(record)) {
        if (peelReleaseTag(call,repository,record.previous_stable_tag).commit_sha!==record.previous_stable_sha) {
            throw new Error('BLOCKED maintenance predecessor stable tag moved.');
        }
        verifyMaintenanceLineage(repository,record.initial_main_sha,record.previous_stable_sha,{...adapters,api:call});
        return;
    }
    const fail=()=>{throw new Error('BLOCKED unqualified maintenance main-to-stable bridge.');};
    if (repository!==pin.repository || !isMaintenanceMainBridge(record)) fail();
    if (peelReleaseTag(call,repository,pin.tag).commit_sha!==pin.stable) fail();
    // Compare provider-owned Git object identities of the complete reviewed caller and main matrix.
    // These are pinned blob identities, not hashes computed from potentially truncated Contents text.
    for (const [commit,path,blob] of [[pin.transport,'.github/workflows/maintenance-workflow-repair.yml','86b1608293cac4ed7c56314cf4379c06d062643e'],
        [pin.stable,'.github/workflows/gallery-workflows.yml','8aa6760e62da2ec44847002add26a8f4c2cd32da']]) {
        const source=call(`repos/${repository}/contents/${path}?ref=${commit}`);
        if (source?.type!=='file' || source.path!==path || source.sha!==blob) fail();
    }
    const merge=call(`repos/${repository}/commits/${pin.main}`);
    if (merge?.sha!==pin.main || merge.parents?.length!==2 || merge.parents[0]?.sha!==pin.stable
        || merge.parents[1]?.sha!==pin.repair || merge.commit?.tree?.sha!==pin.tree) fail();
    const repair=call(`repos/${repository}/commits/${pin.repair}`);
    if (repair?.sha!==pin.repair || repair.commit?.tree?.sha!==pin.tree) fail();
    const pr=call(`repos/${repository}/pulls/${pin.pr}`);
    requireBotPullRequest(pr,repository,pin.repair,'main');
    if (pr.number!==pin.pr || pr.state!=='closed' || pr.merged!==true || pr.merge_commit_sha!==pin.main
        || pr.base?.sha!==pin.stable || pr.base?.repo?.full_name!==repository
        || !['klusik','github-actions[bot]'].includes(pr.merged_by?.login)) fail();
    const review=effectiveOwnerReview(call(`repos/${repository}/pulls/${pin.pr}/reviews?per_page=100`),pr,'klusik',pin.repair);
    if (review?.id!==pin.review) fail();
    const run=call(`repos/${repository}/actions/runs/${pin.run}/attempts/${pin.attempt}`);
    if (run?.id!==Number(pin.run) || run.run_attempt!==pin.attempt || run.head_sha!==pin.transport
        || run.head_branch!=='feature/ci_main_workflow_qualification' || run.path!=='.github/workflows/maintenance-workflow-repair.yml'
        || run.event!=='push' || run.status!=='completed' || run.conclusion!=='success'
        || run.actor?.login!=='klusik' || run.triggering_actor?.login!=='klusik') fail();
    const transport=readCoverageArtifact(repository,pin.artifact,pin.run,pin.digest,'maintenance-qualification-record',
        ['maintenance-qualification.json'],adapters);
    const proof=JSON.parse(new TextDecoder('utf-8',{fatal:true}).decode(transport.entries.get('maintenance-qualification.json')));
    if (proof.schema_version!==1 || proof.kind!=='maintenance-workflow-repair' || proof.repository!==repository
        || proof.candidate_sha!==pin.repair || proof.candidate_tree!==pin.tree || proof.comparison_base!==pin.stable
        || proof.authority_sha!==pin.stable || proof.transport_sha!==pin.transport || proof.run_id!==pin.run
        || proof.run_attempt!==pin.attempt || proof.audit_profile!=='release') fail();
    const jobs=[];let total=null;
    for (let page=1;page<=20;page++) {
        const response=call(`repos/${repository}/actions/runs/${pin.run}/attempts/${pin.attempt}/jobs?per_page=100&page=${page}`);
        if (!Number.isSafeInteger(response?.total_count) || !Array.isArray(response.jobs)
            || total!==null && response.total_count!==total) fail();
        total=response.total_count;jobs.push(...response.jobs);
        if (jobs.length===total) break;
        if (!response.jobs.length || jobs.length>total) fail();
    }
    if (jobs.length!==total || new Set(jobs.map(job=>job.id)).size!==total) fail();
    const prefix='Pinned main FULL and release audit / ',required=[...fullMatrixOwners(2),'Authoritative release audit'];
    const native=jobs.filter(job=>job.name?.startsWith(prefix));
    if (native.length!==required.length || !Array.isArray(proof.native_owners) || proof.native_owners.length!==required.length) fail();
    for (const name of required) {
        const matches=native.filter(job=>job.name===prefix+name);
        if (matches.length!==1) fail();
        const job=matches[0],retained=proof.native_owners.filter(item=>item.id===job.id&&item.name===job.name);
        if (job.status!=='completed' || job.conclusion!=='success' || job.head_sha!==pin.transport
            || job.run_id!==Number(pin.run) || job.run_attempt!==pin.attempt
            || retained.length!==1 || retained[0].status!=='completed' || retained[0].conclusion!=='success') fail();
    }
    const check=call(`repos/${repository}/check-runs/${pin.check}`);
    if (check?.id!==pin.check || check.app?.id!==15368 || check.name!=='Release qualification' || check.head_sha!==pin.repair
        || check.status!=='completed' || check.conclusion!=='success'
        || check.external_id!==`maintenance:${pin.run}:${pin.attempt}:${pin.repair}`) fail();
}

/** Explicit origin chosen by the maintainer before stabilizing a release branch.
 * @typedef {{schema_version:number,version:string,release_branch:string,selected_develop_sha:string,
 *  initial_main_sha:string,previous_stable_tag:string,previous_stable_sha:string}} OriginRecord
 */
/** @typedef {function(string,string=,object=):object} GithubReader */
/** @typedef {function(string,string[],(string|null)=):string} GitReader */
/** Historical predecessor readers, with independently authenticated raw coverage archives for schema 3.
 * @typedef {{git?:GitReader,trustDevelop?:boolean,evidence?:import('./release-promotion.mjs').PublicationEvidence,legacy?:{repository:string,tag:string,tag_object_sha:string,published_commit_sha:string,first_parent_sha:string,release_candidate_sha:string,tree_sha:string,qualification:string,owner:string},predecessorRecord?:import('./release-promotion.mjs').ReleaseRecord,predecessorJobs?:import('./release-promotion.mjs').ReleaseJob[],predecessorArtifacts?:import('./coverage-artifacts.mjs').ArtifactAdapters}} PredecessorAdapters
 */

/** Return only the accepted release path derived from a canonical numeric version.
 * @param {string} version Requested version without prefix.
 * @returns {string} Repository-relative immutable origin metadata path.
 */
export function originPath(version) {
    if (!/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?$/.test(version)) {
        throw new Error('BLOCKED invalid canonical origin version.');
    }
    return '.github/release-origins/v_' + version + '.json';
}

/** Validate an origin record without silently repairing operator-selected identities.
 * @param {OriginRecord} record Explicit checked-in origin record.
 * @param {string} version Version derived from the actual release branch.
 * @returns {void} Throws for malformed, partial or contradictory metadata.
 */
export function validateOriginRecord(record,version) {
    originPath(version);
    if (!record || Object.getPrototypeOf(record) !== Object.prototype
        || JSON.stringify(Object.keys(record).sort()) !== JSON.stringify([
            'initial_main_sha','previous_stable_sha','previous_stable_tag','release_branch',
            'schema_version','selected_develop_sha','version'
        ]) || record.schema_version !== 1 || record.version !== version
        || record.release_branch !== 'release/v_' + version
        || !/^v_(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?$/.test(record.previous_stable_tag)
        || ![record.selected_develop_sha,record.initial_main_sha,record.previous_stable_sha].every(
            sha => typeof sha === 'string' && /^[a-f0-9]{40}$/.test(sha))) {
        throw new Error('BLOCKED malformed, inconsistent or unanchored release origin.');
    }
    // BigInt avoids rounding even when a valid numeric version segment is large.
    const left = version.split('.').map(value => BigInt(value));
    const right = record.previous_stable_tag.slice(2).split('.').map(value => BigInt(value));
    const len = Math.max(left.length,right.length);
    for (let i=0;i<len;i++) {
        if ((left[i] ?? 0n) !== (right[i] ?? 0n)) {
            if ((left[i] ?? 0n) < (right[i] ?? 0n)) {
                throw new Error('BLOCKED release version must advance past previous stable tag.');
            }
            return;
        }
    }
    throw new Error('BLOCKED release version duplicates previous stable tag.');
}

/** Cross-check immutable origins with GitHub server ancestry and checked-out Git blob.
 * @param {OriginRecord} record Parsed file committed into the release branch.
 * @param {string} version Canonical release version.
 * @param {string} candidateSha Exact checkout/release head SHA being qualified.
 * @param {string} repository GitHub owner/repo identity.
 * @param {PredecessorAdapters & import('./coverage-artifacts.mjs').ArtifactAdapters & {publishedMainSha?:string,reconciliation?:Record<string,unknown>}} adapters Hermetic origin/predecessor and complete maintenance archive readers, or the real read-only GitHub/Git transports.
 * @returns {Record<string,string>} Server-checked origin, blob and predecessor identities.
 */
export function verifyReleaseOrigin(record, version, candidateSha, repository, adapters = {}) {
    validateOriginRecord(record,version);
    verifyMaintenanceMainBridge(record,repository,adapters);
    if (!/^[a-f0-9]{40}$/.test(candidateSha) || !/^[\w.-]+\/[\w.-]+$/.test(repository)) {
        throw new Error('BLOCKED exact candidate or repository identity is unavailable.');
    }
    const call = adapters.api ?? api;
    const git = adapters.git ?? command;
    const path = originPath(version);
    const created = git('git',['log','--format=%H','--diff-filter=A',candidateSha,'--',path]).trim().split('\n');
    if (created.length !== 1 || !/^[a-f0-9]{40}$/.test(created[0])) {
        throw new Error('BLOCKED origin marker has no unique introduction commit.');
    }
    const addedAt = created[0];
    const parents = git('git',['rev-list','--parents','-n','1',addedAt]).trim().split(/\s+/);
    if (parents.length !== 2 || parents[0] !== addedAt || parents[1] !== record.selected_develop_sha) {
        throw new Error('BLOCKED release provenance was not initialized immediately from selected develop SHA.');
    }
    const initialBlob = git('git',['rev-parse',addedAt + ':' + path]);
    const candidateBlob = git('git',['rev-parse',candidateSha + ':' + path]);
    if (!/^[a-f0-9]{40}$/.test(initialBlob) || candidateBlob !== initialBlob) {
        throw new Error('BLOCKED immutable release origin record was changed after initialization.');
    }
    const committed = JSON.parse(git('git',['show',addedAt+':'+path]));
    validateOriginRecord(committed,version);
    if (Object.keys(record).some(key=>record[key]!==committed[key])) {
        throw new Error('BLOCKED supplied origin is not the immutable committed record.');
    }
    const predecessor = verifyPredecessor(call,repository,record.previous_stable_tag,adapters);
    const mainSha = call('repos/' + repository + '/git/ref/heads/main').object?.sha;
    const developSha = call('repos/' + repository + '/git/ref/heads/develop').object?.sha;
    if (predecessor.previous_stable_sha !== record.previous_stable_sha || mainSha !== (adapters.publishedMainSha ?? record.initial_main_sha)) {
        throw new Error('BLOCKED previous stable tag moved or current main differs from the selected predecessor.');
    }
    if (!isAncestor(call,repository,record.selected_develop_sha,developSha)
        || !isAncestor(call,repository,addedAt,candidateSha)) {
        throw new Error('BLOCKED release origin develop ancestry was rewritten or mismatched.');
    }
    if (adapters.trustDevelop!==true) {
        verifyPredecessorLineage(call,repository,predecessor,record.selected_develop_sha,adapters);
    }
    const supplement = '.github/release-predecessors/v_' + version + '.json';
    if (version !== '0.126' || repository !== 'klusik/PHP_gallery') {
        const introduction = git('git',['log','--format=%H','--diff-filter=A',candidateSha,'--',supplement]);
        if (introduction !== addedAt || git('git',['rev-parse',addedAt + ':' + supplement])
            !== git('git',['rev-parse',candidateSha + ':' + supplement])) {
            throw new Error('BLOCKED immutable predecessor evidence must be introduced with the origin.');
        }
        const pinned = JSON.parse(git('git',['show',candidateSha + ':' + supplement]));
        if (JSON.stringify(pinned) !== JSON.stringify(predecessor)) {
            throw new Error('BLOCKED predecessor tag object, release-side commit or tree changed.');
        }
    }
    return {origin_commit_sha:addedAt,origin_blob_sha:initialBlob,
        selected_develop_sha:record.selected_develop_sha,initial_main_sha:record.initial_main_sha,
        ...predecessor,source_base:predecessor.previous_release_candidate_sha,
        base_tag:record.previous_stable_tag};
}

/** Separate a tag ref object from its recursively peeled commit and content tree.
 * @param {GithubReader} call GitHub reader.
 * @param {string} repository Exact repository.
 * @param {string} tag Canonical release tag.
 * @returns {{tag_object_sha:string,tag_object_type:string,commit_sha:string,tree_sha:string}} Verified tag identity.
 */
export function peelReleaseTag(call,repository,tag) {
    if (!/^v_(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?$/.test(tag)) {
        throw new Error('BLOCKED malformed immutable release tag.');
    }
    const object = call('repos/'+repository+'/git/ref/tags/'+tag).object;
    const original = {...object};
    let current = object;
    const seen = new Set();
    while (current?.type === 'tag') {
        if (!/^[a-f0-9]{40}$/.test(current.sha) || seen.has(current.sha) || seen.size >= 16) {
            throw new Error('BLOCKED cyclic or malformed annotated tag.');
        }
        seen.add(current.sha);
        const annotated = call('repos/'+repository+'/git/tags/'+current.sha);
        if (annotated.sha !== current.sha) throw new Error('BLOCKED annotated tag identity differs.');
        current = annotated.object;
    }
    if (current?.type !== 'commit' || !/^[a-f0-9]{40}$/.test(current.sha)) {
        throw new Error('BLOCKED tag does not peel to an immutable commit.');
    }
    const commit = call('repos/'+repository+'/commits/'+current.sha);
    if (commit.sha !== current.sha || !/^[a-f0-9]{40}$/.test(commit.commit?.tree?.sha ?? '')) {
        throw new Error('BLOCKED peeled commit or Git tree is unavailable.');
    }
    return {tag_object_sha:original.sha,tag_object_type:original.type,
        commit_sha:current.sha,tree_sha:commit.commit.tree.sha};
}

/** Resolve the published predecessor and release-side candidate without inventing historical CI.
 * @param {GithubReader} call GitHub reader.
 * @param {string} repository Exact repository.
 * @param {string} tag Previous immutable stable tag.
 * @param {PredecessorAdapters} adapters Optional historical asset, literal command, exact hosted predecessor and raw archive readers; schema-3 archives retain provider and byte authentication.
 * @returns {{previous_stable_tag:string,previous_tag_object_sha:string,previous_tag_object_type:string,previous_stable_sha:string,previous_release_candidate_sha:string,previous_tree_sha:string,predecessor_kind:string}} Complete predecessor identities.
 */
export function verifyPredecessor(call,repository,tag,adapters={}) {
    const identity = peelReleaseTag(call,repository,tag);
    if (adapters.trustDevelop===true || process.env.PHP_GALLERY_RELEASE_TRUST_DEVELOP==='1') {
        const merge=call(`repos/${repository}/commits/${identity.commit_sha}`);
        const candidate=merge.parents?.[1]?.sha;
        const source=call(`repos/${repository}/commits/${candidate}`);
        if (merge?.sha!==identity.commit_sha || merge.parents?.length!==2
            || !/^[a-f0-9]{40}$/.test(candidate ?? '') || source?.sha!==candidate
            || source.commit?.tree?.sha!==identity.tree_sha) throw new Error('BLOCKED stable tag and release Git source differ.');
        return {previous_stable_tag:tag,previous_tag_object_sha:identity.tag_object_sha,
            previous_tag_object_type:identity.tag_object_type,previous_stable_sha:identity.commit_sha,
            previous_release_candidate_sha:candidate,previous_tree_sha:identity.tree_sha,predecessor_kind:'protected-main'};
    }
    let published;
    try {published=call('repos/'+repository+'/releases/tags/'+tag);}
    catch (error) {
        if (!error.message.startsWith('BLOCKED_API_NOT_FOUND:')) throw error;
        // A tagged release is the automatic lifecycle boundary; GitHub publication is manual.
        published=null;
    }
    if (published && (published.tag_name !== tag || published.prerelease !== false)) {
        throw new Error('BLOCKED predecessor is not an immutable published stable release.');
    }
    const asset = published?.draft===false ? published.assets?.find(value=>value.name === 'release-evidence.json') : null;
    let qualified;
    let kind;
    let expectedFirstParent;
    if (asset) {
        const proof = adapters.evidence ?? downloadReleaseEvidence(repository,tag,asset);
        if (proof.repository !== repository || proof.version !== tag.slice(2)
            || proof.final_main_sha !== identity.commit_sha || proof.final_tree !== identity.tree_sha
            || proof.ready !== true || proof.automated_result !== 'success' || proof.override !== false
            || !/^[1-9]\d*$/.test(proof.run_id ?? '')) {
            throw new Error('BLOCKED predecessor permanent qualification evidence is inconsistent.');
        }
        qualified = proof.candidate_sha;
        kind = 'qualified';
    } else if (repository==='klusik/PHP_gallery' && tag==='v_0.125') {
        // This exception protects the real pre-evidence v_0.125 only. It is not a CI approval.
        const legacy = adapters.legacy ?? JSON.parse(readFileSync(resolve('.github/release-legacy/v_0.125.json'),'utf8'));
        if (repository !== 'klusik/PHP_gallery' || tag !== 'v_0.125'
            || legacy.repository !== repository || legacy.tag !== tag
            || legacy.tag_object_sha !== identity.tag_object_sha
            || legacy.published_commit_sha !== identity.commit_sha || legacy.tree_sha !== identity.tree_sha
            || legacy.qualification !== 'UNKNOWN_LEGACY' || legacy.owner !== 'klusik') {
            throw new Error('BLOCKED missing qualified predecessor or mismatched explicit legacy bootstrap.');
        }
        expectedFirstParent = legacy.first_parent_sha;
        qualified = legacy.release_candidate_sha;
        kind = 'legacy-v_0.125';
    } else {
        const merge=call('repos/'+repository+'/commits/'+identity.commit_sha);
        qualified=merge.parents?.[1]?.sha;
        if (merge.parents?.length!==2 || !/^[a-f0-9]{40}$/.test(qualified ?? '')) {
            throw new Error('BLOCKED tagged predecessor lacks the standard release merge.');
        }
        const execute=adapters.git ?? command;
        const record=adapters.predecessorRecord ?? loadQualification(repository,qualified,call,execute);
        const request=qualificationRequest(record);
        const run=call('repos/'+repository+'/actions/runs/'+record.run_id);
        // The predecessor's FULL/BASIC archives are distinct from the current release's coverage.
        // Authenticate their original bytes before the shared schema-3 verifier consumes them.
        const coverage=loadReleaseCoverage(record,{...adapters.predecessorArtifacts,api:call});
        // Historical qualification binds Q even when the original release branch was retired.
        verifyQualification(request,run,adapters.predecessorJobs ?? fetchJobs(repository,record.run_id,run.run_attempt,qualified,execute,call),
            record,repository,qualified,{...coverage,api:call,execute});
        requireQualificationChecks(call,repository,request,record.run_attempt);
        const listed=call('repos/'+repository+'/commits/'+identity.commit_sha+'/pulls?per_page=100');
        if (!Array.isArray(listed) || listed.length>=100) throw new Error('BLOCKED predecessor PR inventory incomplete.');
        const matches=listed.filter(pr=>pr.merge_commit_sha===identity.commit_sha && pr.head?.sha===qualified && pr.base?.ref==='main');
        if (matches.length!==1) throw new Error('BLOCKED predecessor merged PR is ambiguous.');
        const pr=call('repos/'+repository+'/pulls/'+matches[0].number);
        requireBotPullRequest(pr,repository,qualified,'main');
        const review=effectiveOwnerReview(call('repos/'+repository+'/pulls/'+pr.number+'/reviews?per_page=100'),pr,repository.split('/')[0],qualified);
        if (!pr.merged || pr.merge_commit_sha!==identity.commit_sha || record.version!==tag.slice(2)
            || pr.head.ref!==record.branch || !review || record.initial_main_sha!==merge.parents[0].sha) {
            throw new Error('BLOCKED predecessor tag, exact CI or human approval differs.');
        }
        expectedFirstParent=record.initial_main_sha;
        kind='qualified';
    }
    if (!/^[a-f0-9]{40}$/.test(qualified ?? '')) throw new Error('BLOCKED previous release candidate identity missing.');
    const previous = call('repos/'+repository+'/commits/'+identity.commit_sha);
    const candidate = call('repos/'+repository+'/commits/'+qualified);
    if (previous.parents?.length !== 2 || previous.parents[1]?.sha !== qualified
        || (expectedFirstParent && previous.parents[0]?.sha !== expectedFirstParent)
        || candidate.sha !== qualified || candidate.commit?.tree?.sha !== identity.tree_sha) {
        throw new Error('BLOCKED predecessor merge parents or release-side Git tree differ.');
    }
    return {previous_stable_tag:tag,previous_tag_object_sha:identity.tag_object_sha,
        previous_tag_object_type:identity.tag_object_type,previous_stable_sha:identity.commit_sha,
        previous_release_candidate_sha:qualified,previous_tree_sha:identity.tree_sha,predecessor_kind:kind};
}

/** Require retained release-side ancestry or an independently verified linear content proof.
 * @param {GithubReader} call GitHub reader.
 * @param {string} repository Exact repository.
 * @param {{previous_release_candidate_sha:string,previous_stable_tag:string,previous_stable_sha:string}} predecessor Verified previous release identities.
 * @param {string} selected Immutable selected develop commit.
 * @param {{git?:GitReader,reconciliation?:Record<string,unknown>}} adapters Optional inert proof readers.
 * @returns {void} Throws when the previous released changes cannot be proven retained.
 */
export function verifyPredecessorLineage(call,repository,predecessor,selected,adapters={}) {
    if (isAncestor(call,repository,predecessor.previous_release_candidate_sha,selected)) return;
    const published = call('repos/'+repository+'/releases/tags/'+predecessor.previous_stable_tag);
    const asset = published.assets?.find(value=>value.name === 'release-reconciliation.json');
    if (!asset) throw new Error('BLOCKED previous released changes lack verified develop reconciliation.');
    const proof = adapters.reconciliation ?? downloadReleaseEvidence(repository,predecessor.previous_stable_tag,asset);
    if (proof.repository !== repository || proof.schema_version!==1
        || proof.state !== 'RECONCILED_EQUIVALENT' || proof.candidate_sha !== predecessor.previous_release_candidate_sha
        || proof.published_main_sha !== predecessor.previous_stable_sha
        || !isAncestor(call,repository,proof.result_sha,selected)) {
        throw new Error('BLOCKED predecessor reconciliation is not retained in selected develop.');
    }
    const content = verifyLinearContent(proof.selected_develop_sha,proof.develop_base_sha,proof.candidate_sha,
        proof.result_sha,adapters.git,proof.resolutions ?? []);
    for (const [key,value] of Object.entries(content)) {
        if (JSON.stringify(proof[key]) !== JSON.stringify(value)) throw new Error('BLOCKED predecessor content evidence differs.');
    }
    if (qualifiedLinearCandidate(call,repository,proof.result_sha) !== proof.qualification_url) {
        throw new Error('BLOCKED predecessor reconciliation lacks exact hosted CI.');
    }
    // The server-owned completed owner dispatch, exact CI and asset digest are checked by reconciliation.
    const run = call('repos/'+repository+'/actions/runs/'+proof.owner_run_id+'/attempts/'+proof.owner_run_attempt);
    if (run.path !== '.github/workflows/release-reconciliation.yml' || run.event !== 'workflow_dispatch'
        || run.head_branch !== 'main' || run.actor?.login !== repository.split('/')[0]
        || run.run_attempt !== Number(proof.owner_run_attempt) || run.status !== 'completed' || run.conclusion !== 'success') {
        throw new Error('BLOCKED predecessor owner reconciliation run is unavailable or unsuccessful.');
    }
}

/** Verify the checked-out branch and output immutable metadata to the qualification gate.
 * @returns {void} Prints verified origin values; never edits a repository file or Git ref.
 */
export function main() {
    const branch = process.env.GITHUB_REF_NAME ?? '';
    const match = /^release\/v_((?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?)$/.exec(branch);
    if (!match || process.env.GITHUB_REF !== 'refs/heads/' + branch) {
        throw new Error('BLOCKED origin verification requires an exact release branch checkout.');
    }
    const path = originPath(match[1]);
    const record = JSON.parse(readFileSync(resolve(path),'utf8'));
    const candidate = command('git',['rev-parse','HEAD']);
    if (candidate !== (process.env.ORIGIN_CHECKOUT_SHA ?? process.env.GITHUB_SHA)) {
        throw new Error('BLOCKED event SHA differs from the origin-verification checkout.');
    }
    const evidence = verifyReleaseOrigin(record,match[1],candidate,process.env.GITHUB_REPOSITORY ?? '',
        {trustDevelop:process.env.PHP_GALLERY_RELEASE_TRUST_DEVELOP==='1'});
    output('GITHUB_OUTPUT','provenance_json='+JSON.stringify(evidence));
    for (const [name,value] of Object.entries(evidence)) output('GITHUB_OUTPUT',name + '=' + value);
    output('GITHUB_STEP_SUMMARY','Verified immutable selected develop origin: ' + evidence.selected_develop_sha
        + '\nOrigin record commit: ' + evidence.origin_commit_sha
        + '\nPrevious stable tag: ' + evidence.previous_stable_tag
        + '\nOriginal published main SHA: ' + evidence.initial_main_sha);
    process.stdout.write('Verified release origin: ' + JSON.stringify(evidence) + '\n');
}
if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
    try { main(); } catch (error) {process.stderr.write(error.message + '\n');process.exitCode = 1;}
}
