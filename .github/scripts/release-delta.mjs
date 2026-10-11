/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-delta.mjs
 * Module Type: Release Delta Evidence
 * Purpose: Bind BASIC release checks to complete Git changes from qualified A to prepared B.
 * Responsibilities:
 *   - Refuse functional, workflow, test, deletion and mode changes during release preparation
 *   - Preserve exact tree/blob identities and the original central BASIC report hash
 *   - Permit the explicitly requested version marker without claiming behavioral independence
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {spawnSync} from 'node:child_process';
import {createHash} from 'node:crypto';
import {readFileSync,writeFileSync} from 'node:fs';
import {resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

/** Exact immutable raw Git change; zero blob/mode denotes an absent side.
 * @typedef {{path:string,status:string,old_blob:string,new_blob:string,old_mode:string,new_mode:string}} ReleaseDeltaChange
 */
/** Complete source identity and approved preparation changes, before BASIC binding.
 * @typedef {{schema_version:number,base_sha:string,base_tree:string,candidate_sha:string,candidate_tree:string,version:string,changes:ReleaseDeltaChange[]}} ReleaseDeltaEvidence
 */
/** Immutable delta and its successful original BASIC report digest.
 * @typedef {ReleaseDeltaEvidence & {profile:string,successful:boolean,basic_report_sha256:string}} QualifiedReleaseDelta
 */

/** Read bounded Git output without trimming source bytes or invoking a shell.
 * @param {string[]} args Literal Git arguments for immutable evidence reads.
 * @returns {string} Complete UTF-8 output; execution, overflow or invalid encoding throws.
 */
function readGitEvidence(args) {
    const child=spawnSync('git',args,{timeout:60000,maxBuffer:16*1024*1024});
    if(child.error || child.status!==0) throw new Error('BLOCKED complete release Git evidence unavailable.');
    return new TextDecoder('utf-8',{fatal:true}).decode(child.stdout);
}

/** Parse a complete NUL-delimited no-rename Git raw inventory without omitting paths.
 * @param {string} raw Original output from git diff --raw --no-abbrev --no-renames -z.
 * @returns {ReleaseDeltaChange[]} Unique complete changes; malformed/truncated evidence throws.
 */
export function parseReleaseRawDelta(raw) {
    if(raw==='') return [];
    if(!raw.endsWith('\0')) throw new Error('BLOCKED truncated release delta.');
    const fields=raw.split('\0');fields.pop();
    if(fields.length%2!==0) throw new Error('BLOCKED incomplete release delta fields.');
    const changes=[],paths=new Set();
    for(let index=0;index<fields.length;index+=2) {
        const match=/^:([0-7]{6}) ([0-7]{6}) ([a-f0-9]{40}) ([a-f0-9]{40}) ([AMD])$/.exec(fields[index]);
        const path=fields[index+1];
        if(!match || !path || paths.has(path) || /[\r\n\0]/.test(path)) throw new Error('BLOCKED malformed or duplicate release delta.');
        paths.add(path);
        changes.push({path,status:match[5],old_blob:match[3],new_blob:match[4],old_mode:match[1],new_mode:match[2]});
    }
    return changes;
}

/** Permit only the current release's owned files and the single runtime version marker.
 * @param {ReleaseDeltaChange} change Exact path/status/blob/mode identity.
 * @param {string} version Canonical requested X.Y[.Z] release version.
 * @param {string} before Complete old bootstrap source, required only for its marker.
 * @param {string} after Complete new bootstrap source, required only for its marker.
 * @returns {void} Returns after validating ownership; unexpected source/mode/deletion changes throw.
 */
export function requireReleaseOwnedChange(change,version,before='',after='') {
    if(!/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(\.(0|[1-9][0-9]*))?$/.test(version)) throw new Error('BLOCKED noncanonical release version.');
    const addedOrigin=change.path==='.github/release-origins/v_'+version+'.json'
        || change.path==='.github/release-predecessors/v_'+version+'.json';
    if(addedOrigin) {
        if(change.status!=='A' || change.old_mode!=='000000' || change.new_mode!=='100644'
            || change.old_blob!=='0'.repeat(40) || !/^[a-f0-9]{40}$/.test(change.new_blob)
            || change.new_blob==='0'.repeat(40)) throw new Error('BLOCKED release origin must be a new immutable regular file.');
        return;
    }
    const paths=['app/bootstrap.php','app/core-manifest.json','app/production-files.json',
        'README.md','TESTING.md','DATABASE.md','ARCHITECTURE.md','PATCH_NOTES.md','release-metadata.json'];
    for(const edition of ['','_CZ','_DE','_SV']) for(const extension of ['tex','pdf']) paths.push('docs/PHP_Gallery_Manual'+edition+'.'+extension);
    if(!paths.includes(change.path) || change.status!=='M' || change.old_mode!=='100644'
        || change.new_mode!=='100644' || [change.old_blob,change.new_blob].some(value=>! /^[a-f0-9]{40}$/.test(value) || value==='0'.repeat(40))) {
        throw new Error('BLOCKED release preparation changed an unowned input: '+change.path);
    }
    if(change.path==='app/bootstrap.php') {
        const pattern=/\bconst\s+CMS_VERSION\s*=\s*(['"])((?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*))?)\1\s*;/g;
        const oldMarkers=[...before.matchAll(pattern)],newMarkers=[...after.matchAll(pattern)];
        if(oldMarkers.length!==1 || newMarkers.length!==1 || newMarkers[0][2]!==version) throw new Error('BLOCKED runtime release marker is ambiguous.');
        const marker=oldMarkers[0],literal=marker[1]+marker[2]+marker[1];
        const replacement=marker[0].replace(literal,marker[1]+version+marker[1]);
        if(before.slice(0,marker.index)+replacement+before.slice(marker.index+marker[0].length)!==after) {
            throw new Error('BLOCKED release changed runtime behavior beyond CMS_VERSION.');
        }
    }
}

/** Collect clean, linear A-to-B evidence and reject every unowned preparation input.
 * @param {string} base Exact already qualified integration A commit SHA.
 * @param {string} candidate Exact prepared release B commit SHA.
 * @param {string} version Canonical requested release version.
 * @param {function(string[]):string} git Complete UTF-8 Git reader; defaults to bounded local immutable reads.
 * @returns {ReleaseDeltaEvidence} Exact trees and all approved changes, without running functional tests.
 */
export function collectReleaseDelta(base,candidate,version,git=readGitEvidence) {
    if(![base,candidate].every(value=>/^[a-f0-9]{40}$/.test(value))
        || !/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(\.(0|[1-9][0-9]*))?$/.test(version)) {
        throw new Error('BLOCKED exact A/B identities and canonical version required.');
    }
    if(git(['rev-parse','HEAD']).trim()!==candidate || git(['status','--porcelain=v1','--untracked-files=all']).trim()
        || git(['merge-base',base,candidate]).trim()!==base || git(['rev-list','--merges',base+'..'+candidate]).trim()) {
        throw new Error('BLOCKED release candidate is dirty, foreign or non-linear.');
    }
    const changes=parseReleaseRawDelta(git(['diff','--raw','--no-abbrev','--no-renames','-z',base,candidate]));
    for(const change of changes) requireReleaseOwnedChange(change,version,
        change.path==='app/bootstrap.php'?git(['show',base+':app/bootstrap.php']):'',
        change.path==='app/bootstrap.php'?git(['show',candidate+':app/bootstrap.php']):'');
    const baseTree=git(['rev-parse',base+'^{tree}']).trim(),candidateTree=git(['rev-parse',candidate+'^{tree}']).trim();
    if(![baseTree,candidateTree].every(value=>/^[a-f0-9]{40}$/.test(value))) throw new Error('BLOCKED exact A/B trees unavailable.');
    return {schema_version:1,base_sha:base,base_tree:baseTree,candidate_sha:candidate,candidate_tree:candidateTree,version,changes};
}

/** Bind the original central BASIC JSON to the exact clean A-to-B source delta.
 * @param {ReleaseDeltaEvidence} evidence Previously validated complete immutable A/B changes.
 * @param {string} reportJson Complete original central report bytes decoded as UTF-8.
 * @returns {QualifiedReleaseDelta} BASIC-qualified delta and original report hash; missing/failed/extra suites throw.
 */
export function bindBasicReleaseDelta(evidence,reportJson) {
    const report=JSON.parse(reportJson),expected=['php-lint-changed','js-lint-changed','source-documentation-changed'];
    if(report.schema_version!==1 || report.profile!=='basic' || report.status!=='PASS'
        || report.source?.head_sha!==evidence.candidate_sha || report.source?.comparison_base!==evidence.base_sha
        || report.source?.worktree_dirty!==false || !Array.isArray(report.tasks) || report.tasks.length!==expected.length
        || !expected.every(id=>report.tasks.filter(task=>task.id===id && task.status==='PASS').length===1)
        || report.tasks.some(task=>['failed','blocked','errors'].some(key=>(task.counts?.[key]??0)!==0))) {
        throw new Error('BLOCKED BASIC release delta report is incomplete, failed or unbound.');
    }
    return {...evidence,profile:'basic',successful:true,basic_report_sha256:'sha256:'+createHash('sha256').update(reportJson).digest('hex')};
}

if(process.argv[1] && resolve(process.argv[1])===fileURLToPath(import.meta.url)) {
    const evidence=collectReleaseDelta(process.argv[2],process.argv[3],process.argv[4]);
    const qualified=bindBasicReleaseDelta(evidence,readFileSync('cache/test-audit/latest.json','utf8'));
    if(!process.env.RUNNER_TEMP) throw new Error('BLOCKED hosted evidence directory is missing.');
    writeFileSync(resolve(process.env.RUNNER_TEMP,'release-delta.json'),JSON.stringify(qualified,null,2)+'\n');
    process.stdout.write('PASS exact prepared release BASIC delta; no functional matrix executed.\n');
}
