/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/integration-bootstrap.mjs
 * Module Type: Integration Authority Release Bootstrap
 * Purpose: Prepare an immutable, fully release-qualified repair of the known broken integration authority.
 * Responsibilities:
 *   - Pin the defective main installation, actual develop and one reviewed feature source
 *   - Build local origin/overlay objects without writing any local or remote branch
 *   - Select genuine legacy FULL release coverage only for the verified recovery chain
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {api,command,fetchReleases} from './release-promotion.mjs';
import {verifyPredecessor,verifyPredecessorLineage,verifyReleaseOrigin,originPath,validateOriginRecord} from './release-origin.mjs';
import {matchingBranchSha} from './release-reconciliation.mjs';
import {requireOwnerRuleset} from './release-owner-authorization.mjs';

/** Immutable recovery provenance; source is the reviewed one-commit feature over actual develop.
 * @typedef {{schema_version:1,repository:string,version:string,main_sha:string,develop_sha:string,source_sha:string,source_tree:string,source_branch:string,retained_origin_sha:string|null,reason:'upload-artifact-digest-prefix'}} BootstrapRecord
 */
/** Read-only server adapter whose structured responses are narrowed at their use sites.
 * @typedef {function(string):import('./release-promotion.mjs').JsonValue} BootstrapApi
 */
/** Literal Git/gh transport; stdin is used only for local object creation.
 * @typedef {function(string,string[],(string|null)=):string} BootstrapCommand
 */

/** Bind recovery to the defective main observed in run 38218271690.
 * Type: string. Units: immutable 40-character Git commit SHA.
 * Scope: this repository's integration-authority installation recovery.
 * Consumers: requireBrokenAuthority and validateBootstrapSource.
 * Rationale: any newer/different installation must use its normal verified lifecycle.
 */
const BROKEN_MAIN='7dcfadf153b1c941fd81edee43272f8accb475a1';
/** Identify the independent workflow blob that produced the observed digest mismatch.
 * Type: string. Units: immutable 40-character Git blob SHA.
 * Scope: the integration workflow installed on the pinned defective main.
 * Consumers: requireBrokenAuthority and the coverage selector's server metadata reader.
 * Rationale: a missing proof or unrelated workflow version cannot authorize recovery.
 */
const BROKEN_WORKFLOW='749a48265f07a10de636ff07bfeacc6c1d9d1cb4';
/** Restrict the historical recovery pins to their actual owning repository.
 * Type: string. Units: GitHub owner/repository identity.
 * Scope: local preparation and release-selector recovery authentication.
 * Consumers: source validation, owner/policy checks and literal API paths.
 * Rationale: these observed installation identities do not establish authority for forks.
 */
const REPOSITORY='klusik/PHP_gallery';
/** Preserve the original interrupted origin when recovering the unpublished version 0.127.3.
 * Type: Map<string,string>. Units: canonical version strings and immutable Git origin commit SHAs.
 * Scope: this known-defect recovery before main or predecessor pins advance.
 * Consumers: prepareIntegrationBootstrap and resolveIntegrationBootstrap.
 * Rationale: deleting an unfinished branch does not authorize replacing its retained origin identity.
 */
const RETAINED_ORIGINS=new Map([['0.127.3','d4f9abd78bebb11a691793b6b1f9815188b21b0e']]);
/** Permit only the integration repair, its full-release handoff, tests and permanent documentation.
 * Type: Set<string>. Units: exact repository-relative authored file paths.
 * Scope: source changes between selected develop and the reviewed repair feature.
 * Consumers: validateBootstrapSource before creating or consuming origin/overlay objects.
 * Rationale: a recovery release must not introduce unrelated product or server-policy changes.
 */
const REPAIR_PATHS=new Set(['.github/workflows/develop-integration.yml',
    '.github/workflows/release-qualification.yml','.github/workflows/release-bootstrap.yml',
    '.github/scripts/develop-integration.mjs','.github/scripts/integration-bootstrap.mjs',
    'tests/develop_integration_test.mjs','tests/hosted_release_policy_test.mjs',
    'RELEASE.md','docs/RELEASE_LIFECYCLE.md','docs/COMPATIBILITY_LIFECYCLE.md']);

/** Derive a canonical new recovery marker path without accepting arbitrary filesystem input.
 * @param {string} version Canonical numeric X.Y or X.Y.Z release version.
 * @returns {string} Repository-relative immutable marker path; malformed versions throw.
 */
export function bootstrapPath(version) {
    originPath(version);
    return '.github/release-bootstrap-recovery/v_'+version+'.json';
}

/** Require the exact known defective installation rather than treating missing CI as bootstrap permission.
 * @param {string} repository Exact repository identity.
 * @param {string} mainSha Current protected main commit.
 * @param {string} workflowBlob Independently observed main integration workflow blob.
 * @returns {void} Throws for any different repository, installation or authority version.
 */
export function requireBrokenAuthority(repository,mainSha,workflowBlob) {
    if (repository!==REPOSITORY || mainSha!==BROKEN_MAIN || workflowBlob!==BROKEN_WORKFLOW) {
        throw new Error('BLOCKED bootstrap is restricted to the known defective integration installation.');
    }
}

/** Check an exact feature source and prohibit unrelated product, runtime, origin or policy changes.
 * @param {BootstrapRecord} record Exact proposed recovery identities.
 * @param {BootstrapCommand} git Literal local Git reader with the pinned source available.
 * @returns {void} Throws for invalid identities, history, changed-path scope or absent digest repairs.
 */
export function validateBootstrapSource(record,git) {
    if (record.schema_version!==1 || record.repository!==REPOSITORY || record.main_sha!==BROKEN_MAIN
        || record.reason!=='upload-artifact-digest-prefix' || !/^feature\/[A-Za-z0-9_./#,-]+$/.test(record.source_branch)
        || record.retained_origin_sha!==null && !/^[a-f0-9]{40}$/.test(record.retained_origin_sha)
        || ![record.develop_sha,record.source_sha,record.source_tree].every(value=>/^[a-f0-9]{40}$/.test(value))) {
        throw new Error('BLOCKED malformed integration bootstrap provenance.');
    }
    bootstrapPath(record.version);
    if (git('git',['rev-list','--parents','-n','1',record.source_sha])!==record.source_sha+' '+record.develop_sha
        || git('git',['rev-parse',record.source_sha+'^{tree}'])!==record.source_tree) {
        throw new Error('BLOCKED recovery source is not one exact commit over selected develop.');
    }
    const paths=git('git',['diff','--name-only',record.develop_sha,record.source_sha]).trim().split('\n').filter(Boolean);
    if (!paths.length || paths.some(path=>!REPAIR_PATHS.has(path))) {
        throw new Error('BLOCKED recovery source contains changes outside the reviewed integration repair scope.');
    }
    for (const [path,output] of [['.github/workflows/develop-integration.yml','artifact_digest'],
        ['.github/workflows/release-qualification.yml','delta_artifact_sha256']]) {
        const yaml=git('git',['show',record.source_sha+':'+path]);
        if (!new RegExp('^\\s+'+output+': sha256:\\$\\{\\{ steps\\.[\\w-]+\\.outputs\\.artifact-digest \\}\\}$','m').test(yaml)) {
            throw new Error('BLOCKED source does not repair the authenticated upload digest format.');
        }
    }
}

/** Validate the preserved O -> C -> Q chain and the exact source-tree overlay used by recovery FULL.
 * @param {BootstrapRecord} record Immutable recovery record from C.
 * @param {string} candidate Current release event or prepared checkout.
 * @param {BootstrapCommand} git Literal Git reader containing the source and release objects.
 * @returns {void} Throws for altered markers, fabricated ancestry or unreviewed overlay content.
 */
export function verifyBootstrapChain(record,candidate,git) {
    validateBootstrapSource(record,git);
    const marker=bootstrapPath(record.version),origin=originPath(record.version);
    const predecessor='.github/release-predecessors/v_'+record.version+'.json';
    const introduced=git('git',['log','--format=%H','--diff-filter=A',candidate,'--',marker]);
    if (!/^[a-f0-9]{40}$/.test(introduced)
        || git('git',['rev-parse',introduced+':'+marker])!==git('git',['rev-parse',candidate+':'+marker])) {
        throw new Error('BLOCKED recovery marker is not uniquely introduced and immutable.');
    }
    const originCommit=git('git',['rev-parse',introduced+'^']);
    if (record.retained_origin_sha!==null && originCommit!==record.retained_origin_sha) {
        throw new Error('BLOCKED recovery replaced the retained immutable origin.');
    }
    if (git('git',['rev-list','--parents','-n','1',introduced])!==introduced+' '+originCommit
        || git('git',['rev-list','--parents','-n','1',originCommit])!==originCommit+' '+record.develop_sha) {
        throw new Error('BLOCKED recovery must preserve actual develop -> immutable origin -> source overlay.');
    }
    const originPaths=git('git',['diff','--name-only',record.develop_sha,originCommit]).trim().split('\n').sort();
    if (JSON.stringify(originPaths)!==JSON.stringify([origin,predecessor].sort())) {
        throw new Error('BLOCKED recovery origin contains unrelated changes.');
    }
    const overlayPaths=git('git',['diff','--name-only',record.source_sha,introduced]).trim().split('\n').sort();
    if (JSON.stringify(overlayPaths)!==JSON.stringify([origin,predecessor,marker].sort())) {
        throw new Error('BLOCKED recovery overlay differs from the reviewed source tree.');
    }
    for (const path of [origin,predecessor]) {
        if (git('git',['rev-parse',originCommit+':'+path])!==git('git',['rev-parse',introduced+':'+path])
            || git('git',['rev-parse',introduced+':'+path])!==git('git',['rev-parse',candidate+':'+path])) {
            throw new Error('BLOCKED recovery changed immutable origin or predecessor bytes.');
        }
    }
    const provenance=JSON.parse(git('git',['show',introduced+':'+origin]));
    validateOriginRecord(provenance,record.version);
    if (provenance.selected_develop_sha!==record.develop_sha || provenance.initial_main_sha!==record.main_sha) {
        throw new Error('BLOCKED recovery origin does not bind the actual develop and defective main.');
    }
}

/** Select legacy full release coverage only when the exact defective authority has a valid recovery marker.
 * @param {string} repository Exact workflow repository.
 * @param {string} candidate Exact release event SHA with its full checkout available.
 * @param {string} version Branch-derived canonical release version.
 * @param {BootstrapApi} call Read-only independent GitHub adapter.
 * @param {BootstrapCommand} git Literal Git reader; source fetch writes no branch reference.
 * @returns {BootstrapRecord|null} Verified repair record or null for the normal strict integration path.
 */
export function resolveIntegrationBootstrap(repository,candidate,version,call,git) {
    const marker=bootstrapPath(version);
    if (!git('git',['ls-tree',candidate,'--',marker])) return null;
    const record=JSON.parse(git('git',['show',candidate+':'+marker]));
    const main=call('repos/'+repository+'/git/ref/heads/main').object?.sha;
    const workflow=call('repos/'+repository+'/contents/.github/workflows/develop-integration.yml?ref='+main);
    requireBrokenAuthority(repository,main,workflow?.sha);
    if (record.version!==version || record.repository!==repository || record.main_sha!==main) {
        throw new Error('BLOCKED recovery record differs from release or current main authority.');
    }
    if (record.retained_origin_sha!==(RETAINED_ORIGINS.get(version) ?? null)) {
        throw new Error('BLOCKED recovery did not preserve the known origin for this version.');
    }
    if (!/^[a-f0-9]{40}$/.test(record.source_sha)) throw new Error('BLOCKED recovery source SHA is malformed.');
    git('git',['fetch','origin',record.source_sha]);
    verifyBootstrapChain(record,candidate,git);
    return record;
}

/** Insert generated JSON blobs through recursive tree construction without changing an index or branch.
 * @param {string} tree Existing immutable tree object.
 * @param {Array<{path:string,blob:string}>} additions New canonical metadata paths and blob object IDs.
 * @param {BootstrapCommand} git Local object transport; only ls-tree and mktree are used.
 * @returns {string} Newly created tree object retaining every unrelated source entry.
 */
function overlayTree(tree,additions,git) {
    const entries=new Map(git('git',['ls-tree','-z',tree]).split('\0').filter(Boolean).map(row=>{
        const match=/^(\d{6}) (blob|tree|commit) ([a-f0-9]{40})\t([\s\S]+)$/.exec(row);
        if (!match) throw new Error('BLOCKED malformed source tree entry.');
        return [match[4],{mode:match[1],type:match[2],sha:match[3]}];
    }));
    const groups=new Map();
    for (const addition of additions) {
        const [name,...tail]=addition.path.split('/');
        if (!tail.length) entries.set(name,{mode:'100644',type:'blob',sha:addition.blob});
        else {if (!groups.has(name)) groups.set(name,[]);groups.get(name).push({path:tail.join('/'),blob:addition.blob});}
    }
    for (const [name,children] of groups) {
        const prior=entries.get(name);
        if (prior && prior.type!=='tree') throw new Error('BLOCKED metadata directory collides with a non-directory.');
        const child=overlayTree(prior?.sha ?? git('git',['mktree'],''),children,git);
        entries.set(name,{mode:'040000',type:'tree',sha:child});
    }
    return git('git',['mktree','-z'],[...entries].map(([name,entry])=>entry.mode+' '+entry.type+' '+entry.sha+'\t'+name+'\0').join('')).trim();
}

/** Build the immutable origin and exact feature-tree overlay as local unattached objects.
 * @param {BootstrapRecord} record Validated defect and source identities.
 * @param {{schema_version:number,version:string,release_branch:string,selected_develop_sha:string,initial_main_sha:string,previous_stable_tag:string,previous_stable_sha:string}} origin Independently verified fresh origin data.
 * @param {Record<string,string>} predecessor Independently verified immutable predecessor identity fields.
 * @param {BootstrapCommand} git Local Git object transport; branch/index writes are never issued.
 * @returns {{candidate_sha:string,origin_sha:string}} Unattached C and O objects verified against the source and actual develop.
 */
export function buildBootstrapCandidate(record,origin,predecessor,git) {
    validateBootstrapSource(record,git);
    validateOriginRecord(origin,record.version);
    if (origin.selected_develop_sha!==record.develop_sha || origin.initial_main_sha!==record.main_sha) {
        throw new Error('BLOCKED supplied recovery origin differs from the pinned authority.');
    }
    const marker=bootstrapPath(record.version);
    const originEntries=[[originPath(record.version),origin],['.github/release-predecessors/v_'+record.version+'.json',predecessor]];
    for (const [path] of [...originEntries,[marker,record]]) {
        if (git('git',['ls-tree',record.source_sha,'--',path])) throw new Error('BLOCKED new immutable recovery metadata already exists.');
    }
    const retained=record.retained_origin_sha;
    if (retained && JSON.stringify(JSON.parse(git('git',['show',retained+':'+originPath(record.version)])))!==JSON.stringify(origin)) {
        throw new Error('BLOCKED retained origin differs from independently verified pins.');
    }
    const blobs=originEntries.map(([path,value])=>({path,blob:retained?git('git',['rev-parse',retained+':'+path])
        :git('git',['hash-object','-w','--stdin'],JSON.stringify(value,null,2)+'\n')}));
    const originCommit=retained ?? git('git',['commit-tree',overlayTree(git('git',['rev-parse',record.develop_sha+'^{tree}']),blobs,git),
        '-p',record.develop_sha,'-m','Initialize immutable integration-repair release origin']);
    const markerBlob=git('git',['hash-object','-w','--stdin'],JSON.stringify(record,null,2)+'\n');
    const overlay=overlayTree(record.source_tree,[...blobs,{path:marker,blob:markerBlob}],git);
    const candidate=git('git',['commit-tree',overlay,'-p',originCommit,'-m','Apply reviewed integration-authority repair for full release qualification']);
    verifyBootstrapChain(record,candidate,git);
    return {candidate_sha:candidate,origin_sha:originCommit};
}

/** Prepare a new local recovery candidate after independently checking owner, policies, leases and predecessor.
 * @param {{version:string,sourceBranch:string,prepare:boolean}} request New release version, existing feature branch and explicit local-object preparation choice.
 * @param {{api?:BootstrapApi,git?:BootstrapCommand}} adapters Optional hermetic provider and command transports.
 * @returns {{record:BootstrapRecord,origin:{schema_version:number,version:string,release_branch:string,selected_develop_sha:string,initial_main_sha:string,previous_stable_tag:string,previous_stable_sha:string},predecessor:Record<string,string>,candidate_sha:string|null,push_command:string|null}} Read-only plan or local candidate and maintainer-only normal push command; no ref is written.
 */
export function prepareIntegrationBootstrap(request,adapters={}) {
    const call=adapters.api ?? api,git=adapters.git ?? command;
    const marker=bootstrapPath(request.version),branch='release/v_'+request.version;
    if (!/^feature\/[A-Za-z0-9_./#,-]+$/.test(request.sourceBranch)) throw new Error('BLOCKED feature source required.');
    const owner=call('user').login;
    if (owner!=='klusik' || !['admin','maintain'].includes(call('repos/'+REPOSITORY+'/collaborators/'+owner+'/permission').permission)) {
        throw new Error('BLOCKED bootstrap preparation requires the repository owner.');
    }
    if (!/^https:\/\/github\.com\/klusik\/PHP_gallery(?:\.git)?$/.test(git('git',['remote','get-url','origin']))
        || git('git',['status','--porcelain'])) throw new Error('BLOCKED use a clean checkout with the canonical origin.');
    requireOwnerRuleset(call,REPOSITORY,'main','Reviewed release promotion to main','Release qualification');
    requireOwnerRuleset(call,REPOSITORY,'develop','Reviewed main-to-develop release reconciliation','Complete required CI matrix');
    const main=call('repos/'+REPOSITORY+'/git/ref/heads/main').object?.sha;
    requireBrokenAuthority(REPOSITORY,main,call('repos/'+REPOSITORY+'/contents/.github/workflows/develop-integration.yml?ref='+main).sha);
    const develop=call('repos/'+REPOSITORY+'/git/ref/heads/develop').object?.sha;
    const sourcePath='repos/'+REPOSITORY+'/git/ref/heads/'+request.sourceBranch.split('/').map(encodeURIComponent).join('/');
    const source=call(sourcePath).object?.sha;
    if (![develop,source].every(value=>/^[a-f0-9]{40}$/.test(value))) throw new Error('BLOCKED source/develop identity unavailable.');
    if (git('git',['branch','--show-current'])!==request.sourceBranch || git('git',['rev-parse','HEAD'])!==source) {
        throw new Error('BLOCKED checkout must be the exact pushed repair feature source.');
    }
    git('git',['fetch','origin','--tags']);
    const record={schema_version:1,repository:REPOSITORY,version:request.version,main_sha:main,develop_sha:develop,
        source_sha:source,source_tree:git('git',['rev-parse',source+'^{tree}']),source_branch:request.sourceBranch,
        retained_origin_sha:RETAINED_ORIGINS.get(request.version) ?? null,reason:'upload-artifact-digest-prefix'};
    validateBootstrapSource(record,git);
    const tagRefs=JSON.parse(git('gh',['api','repos/'+REPOSITORY+'/git/matching-refs/tags/v_','--paginate','--slurp'])).flat();
    const stable=tagRefs.filter(ref=>/^refs\/tags\/v_\d+\.\d+(?:\.\d+)?$/.test(ref.ref)
        && git('git',['rev-parse',ref.ref+'^{commit}'])===main);
    if (stable.length!==1) throw new Error('BLOCKED current main needs one unambiguous stable predecessor.');
    const predecessor=verifyPredecessor(call,REPOSITORY,stable[0].ref.slice(10));
    verifyPredecessorLineage(call,REPOSITORY,predecessor,develop,{git});
    const absent=()=>{
        if (matchingBranchSha(call,REPOSITORY,branch)
            || call('repos/'+REPOSITORY+'/git/matching-refs/tags/v_'+request.version).some(ref=>ref.ref==='refs/tags/v_'+request.version)
            || fetchReleases(REPOSITORY,git).some(release=>release.tag_name==='v_'+request.version)) {
            throw new Error('BLOCKED target release branch, tag or draft already exists. Choose a fresh version.');
        }
    };
    absent();
    const origin={schema_version:1,version:request.version,release_branch:branch,selected_develop_sha:develop,
        initial_main_sha:main,previous_stable_tag:predecessor.previous_stable_tag,previous_stable_sha:main};
    validateOriginRecord(origin,request.version);
    if (record.retained_origin_sha) {
        git('git',['fetch','origin',record.retained_origin_sha]);
        const retained=JSON.parse(git('git',['show',record.retained_origin_sha+':'+originPath(request.version)]));
        verifyReleaseOrigin(retained,request.version,record.retained_origin_sha,REPOSITORY,{git,api:call});
        if (JSON.stringify(retained)!==JSON.stringify(origin)) throw new Error('BLOCKED retained origin pins differ; never reinitialize this version.');
    }
    const originEntries=[[originPath(request.version),origin],['.github/release-predecessors/v_'+request.version+'.json',predecessor]];
    for (const [path] of [...originEntries,[marker,record]]) {
        if (git('git',['ls-tree',source,'--',path])) throw new Error('BLOCKED new immutable recovery metadata already exists.');
    }
    const result={record,origin,predecessor,candidate_sha:null,push_command:null};
    if (!request.prepare) return result;
    const candidate=buildBootstrapCandidate(record,origin,predecessor,git).candidate_sha;
    absent();
    if (call('repos/'+REPOSITORY+'/git/ref/heads/main').object?.sha!==main
        || call('repos/'+REPOSITORY+'/git/ref/heads/develop').object?.sha!==develop
        || call(sourcePath).object?.sha!==source
        || JSON.stringify(verifyPredecessor(call,REPOSITORY,predecessor.previous_stable_tag))!==JSON.stringify(predecessor)) {
        throw new Error('BLOCKED refs or predecessor changed while preparing local recovery objects.');
    }
    return {...result,candidate_sha:candidate,push_command:'git push --force-with-lease=refs/heads/'+branch+': origin '+candidate+':refs/heads/'+branch};
}

/** Print the owner recovery plan or local-object candidate; publishing remains an explicit maintainer command.
 * @returns {void} Prints reviewed identities and optional normal push command; errors set nonzero exit status.
 */
function main() {
    const args=process.argv.slice(2);
    const prepare=args.includes('--prepare');
    const version=args[args.indexOf('--version')+1],sourceBranch=args[args.indexOf('--source')+1];
    if (args.length!==4+(prepare?1:0) || !args.includes('--version') || !args.includes('--source')
        || args.some(arg=>arg.startsWith('--') && !['--prepare','--version','--source'].includes(arg))) {
        throw new Error('Usage: node .github/scripts/integration-bootstrap.mjs --version X.Y.Z --source feature/name [--prepare]');
    }
    process.stdout.write(JSON.stringify(prepareIntegrationBootstrap({version,sourceBranch,prepare}),null,2)+'\n');
}
if (process.argv[1] && import.meta.url===pathToFileURL(resolve(process.argv[1])).href) {
    try {main();} catch (error) {process.stderr.write(error.message+'\n');process.exitCode=1;}
}
