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

/** Explicit origin chosen by the maintainer before stabilizing a release branch.
 * @typedef {{schema_version:number,version:string,release_branch:string,selected_develop_sha:string,
 *  initial_main_sha:string,previous_stable_tag:string,previous_stable_sha:string}} OriginRecord
 */
/** @typedef {function(string,string=,object=):object} GithubReader */
/** @typedef {function(string,string[]):string} GitReader */

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
            sha => typeof sha === 'string' && /^[a-f0-9]{40}$/.test(sha))
        || record.initial_main_sha !== record.previous_stable_sha) {
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
 * @param {{api?:GithubReader,git?:GitReader,evidence?:Record<string,unknown>,legacy?:Record<string,unknown>,publishedMainSha?:string,reconciliation?:Record<string,unknown>}} adapters Hermetic readers or the real GitHub/Git adapters.
 * @returns {Record<string,string>} Server-checked origin, blob and predecessor identities.
 */
export function verifyReleaseOrigin(record, version, candidateSha, repository, adapters = {}) {
    validateOriginRecord(record,version);
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
    verifyPredecessorLineage(call,repository,predecessor,record.selected_develop_sha,adapters);
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
 * @param {{evidence?:Record<string,unknown>,legacy?:Record<string,unknown>,predecessorRecord?:import('./release-promotion.mjs').ReleaseRecord,predecessorJobs?:import('./release-promotion.mjs').ReleaseJob[]}} adapters Optional inert historical asset or exact hosted predecessor fixtures.
 * @returns {{previous_stable_tag:string,previous_tag_object_sha:string,previous_tag_object_type:string,previous_stable_sha:string,previous_release_candidate_sha:string,previous_tree_sha:string,predecessor_kind:string}} Complete predecessor identities.
 */
export function verifyPredecessor(call,repository,tag,adapters={}) {
    const identity = peelReleaseTag(call,repository,tag);
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
        const record=adapters.predecessorRecord ?? loadQualification(repository,qualified,call);
        const request=qualificationRequest(record);
        const run=call('repos/'+repository+'/actions/runs/'+record.run_id);
        // Historical qualification binds Q even when the original release branch was retired.
        verifyQualification(request,run,adapters.predecessorJobs ?? fetchJobs(repository,record.run_id,run.run_attempt,qualified),record,repository,qualified);
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
    const evidence = verifyReleaseOrigin(record,match[1],candidate,process.env.GITHUB_REPOSITORY ?? '');
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
