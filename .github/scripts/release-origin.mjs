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
import {api,command,output} from './release-promotion.mjs';
import {isAncestor} from './release-reconciliation.mjs';

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
 * @param {{api?:GithubReader,git?:GitReader}} adapters Hermetic readers or the real GitHub/Git adapters.
 * @returns {{origin_commit_sha:string,selected_develop_sha:string,initial_main_sha:string,previous_stable_tag:string,previous_stable_sha:string}} Server-checked origin evidence.
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
    const tagRef = call('repos/' + repository + '/git/ref/tags/' + record.previous_stable_tag);
    if (tagRef.object?.type !== 'commit' || tagRef.object.sha !== record.previous_stable_sha) {
        throw new Error('BLOCKED previous stable tag moved or is not a direct commit ref.');
    }
    const mainSha = call('repos/' + repository + '/git/ref/heads/main').object?.sha;
    const developSha = call('repos/' + repository + '/git/ref/heads/develop').object?.sha;
    if (!isAncestor(call,repository,record.previous_stable_sha,mainSha)
        || !isAncestor(call,repository,record.initial_main_sha,record.selected_develop_sha)
        || !isAncestor(call,repository,record.selected_develop_sha,developSha)
        || !isAncestor(call,repository,addedAt,candidateSha)) {
        throw new Error('BLOCKED release origin main/develop ancestry was rewritten or mismatched.');
    }
    return {origin_commit_sha:addedAt,selected_develop_sha:record.selected_develop_sha,
        initial_main_sha:record.initial_main_sha,previous_stable_tag:record.previous_stable_tag,
        previous_stable_sha:record.previous_stable_sha};
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
    if (candidate !== process.env.GITHUB_SHA) {
        throw new Error('BLOCKED event SHA differs from the origin-verification checkout.');
    }
    const evidence = verifyReleaseOrigin(record,match[1],candidate,process.env.GITHUB_REPOSITORY ?? '');
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
