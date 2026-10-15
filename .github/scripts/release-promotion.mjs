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
import {evidenceMatrixOwners,fullMatrixOwners,requireCurrentWorkflowOwners,requireIntegrationBasicProfile} from './ci-owner-contract.mjs';
import {createHash} from 'node:crypto';
import {readFileSync,writeFileSync,appendFileSync} from 'node:fs';
import {resolve} from 'node:path';
import {isDeepStrictEqual} from 'node:util';
import {readCoverageArtifact} from './coverage-artifacts.mjs';
import {bindTrustedBasicReport} from './trusted-basic.mjs';
import {verifyPredecessor} from './release-origin.mjs';
import {requireBotPullRequest,effectiveOwnerReview,qualificationCheckBinding,integrationCheckBinding} from './release-owner-authorization.mjs';

/** Recursive JSON transport values; domain validators narrow the fields they consume.
 * @typedef {null|boolean|number|string|JsonValue[]|{[key: string]: JsonValue}} JsonValue
 */
/** Exact immutable qualification inputs; normal automatic completion never overrides red CI.
 * @typedef {{runId:string,candidate:string,branch:string,mode:string,override:boolean,reason:string,acceptedFailures:string[],manualReview:string}} ReleaseRequest
 */
/** GitHub's server-owned job identity and outcome; optional id binds real API rows to their check details, with optional workflow/run fields used for exact integration jobs.
 * @typedef {{id?:number,name:string,status:string,conclusion:string|null,html_url:string,workflow_name?:string,run_id?:number,run_attempt?:number,actual_name?:string}} ReleaseJob
 */
/** Immutable integration artifact, source workflow and exact candidate identity.
 * @typedef {{candidate_sha:string,candidate_tree:string,run_id:string,run_attempt:string,workflow_sha:string,workflow_path:string,check_run_id:number,artifact_id:number,artifact_sha256:string}} IntegrationIdentity
 */
/** One protected-develop authority transition anchored directly to a main-owned integration.
 * @typedef {{anchor:{identity:IntegrationIdentity,evidence:{payload:JsonValue,provider:{artifact_id:number,artifact_digest:string,run_id:string}}},inherited_basic:{schema_version:1,authority_sha:string,source_sha:string,source_tree:string,comparison_base:string,profile:'basic',suites:string[],successful:true,report_sha256:string,root_binding:'trusted-evaluator-candidate-root-v1',artifact_id:number,artifact_sha256:string}}} IntegrationAuthorityUpdate
 */
/** Qualification record produced by the aggregate release gate.
 * @typedef {{repository:string,branch:string,candidate_sha:string,source_base:string,version:string,run_id:string,run_attempt:string,ready:boolean,origin_commit_sha:string,selected_develop_sha:string,initial_main_sha:string,previous_stable_tag:string,previous_stable_sha:string,origin_blob_sha:string,previous_tag_object_sha:string,
 * previous_tag_object_type:string,previous_release_candidate_sha:string,previous_tree_sha:string,predecessor_kind:string,
 * automated_result:string,override:boolean,lifecycle_schema?:number,coverage_schema?:number,winapp_changed?:boolean,installer_required?:boolean,
 * integration?:IntegrationIdentity,
 * delta?:{base_sha:string,candidate_sha:string,profile:string,changed_paths:string[],artifact_id:number,artifact_sha256:string}}} ReleaseRecord
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
        const providerFailure=executable==='gh' && args[0]==='api' && /HTTP 5\d\d\b/.test(result.stderr ?? '');
        throw new Error((denied ? 'BLOCKED_API_PERMISSION: ' : missing ? 'BLOCKED_API_NOT_FOUND: ' : providerFailure ? 'BLOCKED_API_PROVIDER: ' : 'BLOCKED_COMMAND: ')+executable+' command failed ('+(result.status ?? 'unavailable')+').');
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

/** Authenticate exact-A integration through retained main history or one directly anchored protected-develop authority update.
 * @param {ReleaseRecord} record Aggregate release record containing integration identity and provider digest.
 * @param {string} repository Exact owner/repository identity.
 * @param {{api?:function(string,string=,(JsonValue|null)=):JsonValue,execute?:function(string,string[],(string|null)=):string,integrationJobs?:ReleaseJob[],anchorIntegrationJobs?:ReleaseJob[],anchorArtifacts?:{download?:function(string,number):Buffer},inheritedBasicArtifacts?:{download?:function(string,number):Buffer},integrationEvidence?:{payload:JsonValue,provider:{artifact_id:number,artifact_digest:string,run_id:string}}}} adapters Authenticated artifact and server readers; anchorIntegrationJobs, anchorArtifacts and inheritedBasicArtifacts isolate original main-owned jobs and complete independent ZIP bytes in hermetic fixtures only.
 * @returns {{candidate_sha:string,candidate_tree:string,workflow_sha:string,run_id:string,run_attempt:string}} Verified exact-A identity; incomplete transport, ancestry, checks, owner authorization or inherited BASIC proof throws. Historical authority validation never requires moving develop/source refs.
 */
export function verifyIntegrationEvidence(record,repository,adapters={}) {
    const evidence=adapters.integrationEvidence;
    const identity=record.integration;
    const payload=evidence?.payload;
    const provider=evidence?.provider;
    const sha=value=>typeof value==='string' && /^[a-f0-9]{40}$/.test(value);
    const digest=value=>typeof value==='string' && /^sha256:[a-f0-9]{64}$/.test(value);
    if (record.lifecycle_schema!==3 || record.coverage_schema!==1 || !identity || !payload || !provider
        || !sha(identity.candidate_sha) || identity.candidate_sha!==record.selected_develop_sha
        || !sha(identity.candidate_tree) || !sha(identity.workflow_sha)
        || identity.workflow_path!=='.github/workflows/develop-integration.yml'
        || !/^[1-9]\d*$/.test(identity.run_id ?? '') || !/^[1-9]\d*$/.test(identity.run_attempt ?? '')
        || !Number.isSafeInteger(identity.check_run_id) || identity.check_run_id<1
        || !Number.isSafeInteger(identity.artifact_id) || identity.artifact_id<1 || !digest(identity.artifact_sha256)
        || provider.artifact_id!==identity.artifact_id || provider.artifact_digest!==identity.artifact_sha256
        || provider.run_id!==identity.run_id) {
        throw new Error('BLOCKED exact develop integration artifact or provider identity is missing or inconsistent.');
    }
    const sourceSha=payload.source_sha,sourceBranch=payload.source_branch,developBase=payload.expected_develop_sha;
    const validFeatureBranch=typeof sourceBranch==='string' && /^feature\//.test(sourceBranch)
        && !/[\x00-\x20\x7f~^:?*\[\\]/.test(sourceBranch) && !sourceBranch.includes('@{')
        && !sourceBranch.includes('..') && !sourceBranch.endsWith('/') && !sourceBranch.endsWith('.')
        && sourceBranch.split('/').every(part=>part.length>0 && !part.startsWith('.') && !part.endsWith('.lock'));
    if (payload.schema_version!==1 || payload.repository!==repository || !validFeatureBranch || !sha(sourceSha)
        || !sha(developBase) || payload.source_base!==developBase
        || payload.candidate_sha!==identity.candidate_sha || payload.candidate_tree!==identity.candidate_tree
        || payload.workflow_sha!==identity.workflow_sha || payload.workflow_path!==identity.workflow_path
        || String(payload.run_id)!==identity.run_id || String(payload.run_attempt)!==identity.run_attempt
        || !Array.isArray(payload.native_owners)) {
        throw new Error('BLOCKED integration payload does not match its immutable release record.');
    }
    const call=adapters.api;
    if (typeof call!=='function') throw new Error('BLOCKED integration server evidence reader is unavailable.');
    const authorityMode=Object.hasOwn(payload,'authority_update');
    if (authorityMode) {
        const authority=payload.authority_update,anchor=authority?.anchor,basic=authority?.inherited_basic;
        const exactFields=(value,fields)=>value!==null && typeof value==='object' && !Array.isArray(value)
            && JSON.stringify(Object.keys(value).sort())===JSON.stringify(fields.slice().sort());
        if (!exactFields(authority,['anchor','inherited_basic']) || !exactFields(anchor,['identity','evidence'])
            || !exactFields(anchor.identity,['candidate_sha','candidate_tree','workflow_sha','workflow_path','run_id',
                'run_attempt','check_run_id','artifact_id','artifact_sha256'])
            || !exactFields(anchor.evidence,['payload','provider'])
            || !exactFields(anchor.evidence.provider,['artifact_id','artifact_digest','run_id'])
            || !anchor.evidence.payload || typeof anchor.evidence.payload!=='object'
            || Object.hasOwn(anchor.evidence.payload,'authority_update')
            || !exactFields(basic,['schema_version','authority_sha','source_sha','source_tree','comparison_base',
                'profile','suites','successful','report_sha256','root_binding','artifact_id','artifact_sha256'])
            || anchor.identity.candidate_sha!==identity.workflow_sha || identity.workflow_sha!==developBase
            || basic.schema_version!==1 || basic.authority_sha!==identity.workflow_sha || basic.source_sha!==sourceSha
            || !sha(basic.source_tree) || basic.comparison_base!==developBase || basic.profile!=='basic'
            || JSON.stringify(basic.suites)!==JSON.stringify(['php-lint','js-lint','source-documentation-changed'])
            || basic.successful!==true || !digest(basic.report_sha256)
            || !Number.isSafeInteger(basic.artifact_id) || basic.artifact_id<1 || !digest(basic.artifact_sha256)
            || basic.root_binding!=='trusted-evaluator-candidate-root-v1'
            || JSON.stringify(payload.basic_profile)!==JSON.stringify(['php-lint-changed','js-lint-changed','source-documentation-changed'])
            || ['recovery_mode','observed_develop_sha','ref_write','mode','override','integration_recovery','harness_installation']
                .some(key=>Object.hasOwn(payload,key))) {
            throw new Error('BLOCKED authority update lacks a direct main-owned anchor or exact inherited whole BASIC proof.');
        }
        const transport=readCoverageArtifact(repository,anchor.identity.artifact_id,anchor.identity.run_id,
            anchor.identity.artifact_sha256,'develop-integration-record',['develop-integration.json'],
            {...adapters.anchorArtifacts,api:call});
        const original=JSON.parse(new TextDecoder('utf-8',{fatal:true}).decode(transport.entries.get('develop-integration.json')));
        if (!isDeepStrictEqual(original,anchor.evidence.payload) || !isDeepStrictEqual(transport.provider,anchor.evidence.provider)) {
            throw new Error('BLOCKED embedded authority anchor differs from its authenticated original archive bytes.');
        }
        verifyIntegrationEvidence({lifecycle_schema:3,coverage_schema:1,selected_develop_sha:identity.workflow_sha,
            integration:anchor.identity},repository,{api:call,execute:adapters.execute,
            integrationEvidence:anchor.evidence,integrationJobs:adapters.anchorIntegrationJobs});
        requireCurrentWorkflowOwners(repository,identity.workflow_sha,call);
        const inherited=readCoverageArtifact(repository,basic.artifact_id,identity.run_id,basic.artifact_sha256,
            'develop-integration-inherited-basic',['trusted-basic.json','trusted-basic.json.report.json'],
            {...adapters.inheritedBasicArtifacts,api:call});
        const originalBasic=JSON.parse(new TextDecoder('utf-8',{fatal:true}).decode(inherited.entries.get('trusted-basic.json')));
        const recordedBasic={...basic};
        delete recordedBasic.artifact_id;delete recordedBasic.artifact_sha256;
        if (!isDeepStrictEqual(originalBasic,recordedBasic)) {
            throw new Error('BLOCKED inherited BASIC evidence differs from its independent native job archive.');
        }
        const reportBytes=inherited.entries.get('trusted-basic.json.report.json');
        const centralReport=JSON.parse(new TextDecoder('utf-8',{fatal:true}).decode(reportBytes));
        const bound=bindTrustedBasicReport(centralReport,{authority:identity.workflow_sha,source:sourceSha,
            tree:basic.source_tree,base:developBase},reportBytes);
        if (!isDeepStrictEqual(bound,originalBasic)) {
            throw new Error('BLOCKED inherited BASIC central report bytes do not reproduce the recorded successful evidence.');
        }
    }
    const requiredLabels=evidenceMatrixOwners(payload.native_owners,repository,identity.workflow_sha,call);
    if (authorityMode && JSON.stringify(requiredLabels)!==JSON.stringify(fullMatrixOwners())) {
        throw new Error('BLOCKED authority update requires the current PHP 8.1/8.5 native FULL owners.');
    }
    const requiredOwners=requiredLabels.map(name=>'Develop integration / '+name);
    const owners=payload.native_owners;
    if (owners.length!==requiredOwners.length || owners.some(owner=>!owner || !Number.isSafeInteger(owner.id) || owner.id<1
        || owner.status!=='completed' || owner.conclusion!=='success')
        || new Set(owners.map(owner=>owner.id)).size!==owners.length
        || requiredOwners.some(name=>owners.filter(owner=>owner.name===name).length!==1)) {
        throw new Error('BLOCKED exact-A integration payload lacks one or more full native qualification owners.');
    }
    const run=call(`repos/${repository}/actions/runs/${identity.run_id}/attempts/${identity.run_attempt}`);
    if (run?.id!==Number(identity.run_id) || run.run_attempt!==Number(identity.run_attempt)
        || run.path!==identity.workflow_path || run.event!=='workflow_dispatch' || run.head_branch!==(authorityMode?'develop':'main')
        || run.head_sha!==identity.workflow_sha || run.name!=='Integrate into develop'
        || run.status!=='completed' || run.conclusion!=='success') {
        throw new Error('BLOCKED integration workflow attempt is not the successful trusted source run.');
    }
    if (authorityMode) {
        const owner=repository.split('/')[0];
        if (run.actor?.login?.toLowerCase()!==owner.toLowerCase()
            || run.triggering_actor?.login?.toLowerCase()!==owner.toLowerCase()
            || !['admin','maintain'].includes(call(`repos/${repository}/collaborators/${encodeURIComponent(owner)}/permission`)?.permission)) {
            throw new Error('BLOCKED protected-develop authority update requires the repository owner with maintainer permission.');
        }
    }
    const checkInventory=call(`repos/${repository}/commits/${identity.candidate_sha}/check-runs?per_page=100&filter=all`);
    if (!Array.isArray(checkInventory?.check_runs) || checkInventory.total_count!==checkInventory.check_runs.length
        || checkInventory.total_count>=100) throw new Error('BLOCKED exact-A integration check inventory is incomplete.');
    for (const name of ['Develop integration qualification','Complete required CI matrix']) {
        const matches=checkInventory.check_runs.filter(check=>check.name===name
            && check.external_id===`integration:${identity.run_id}:${identity.run_attempt}:${identity.candidate_sha}`);
        const newer=checkInventory.check_runs.some(check=>{
            const binding=integrationCheckBinding(check.external_id);
            return check.name===name && check.app?.id===15368 && check.head_sha===identity.candidate_sha
                && binding?.sha===identity.candidate_sha && check.id>matches[0]?.id;
        });
        if (matches.length!==1 || newer || matches[0].app?.id!==15368 || matches[0].head_sha!==identity.candidate_sha
            || matches[0].status!=='completed' || matches[0].conclusion!=='success'
            || (name==='Develop integration qualification' && matches[0].id!==identity.check_run_id)) {
            throw new Error('BLOCKED exact-A integration qualification check is missing, stale or red: '+name);
        }
    }
    const workflowCommit=call(`repos/${repository}/commits/${identity.workflow_sha}`);
    if (workflowCommit?.sha!==identity.workflow_sha) {
        throw new Error('BLOCKED integration workflow source is unavailable.');
    }
    if (!authorityMode) {
        const mainRef=call(`repos/${repository}/git/ref/heads/main`),mainSha=mainRef?.object?.sha;
        if (mainRef?.ref!=='refs/heads/main' || mainRef.object?.type!=='commit' || !sha(mainSha)) {
            throw new Error('BLOCKED integration current main ref is unavailable.');
        }
        const lineage=mainSha===identity.workflow_sha ? {status:'identical',merge_base_commit:{sha:identity.workflow_sha}}
            : call(`repos/${repository}/compare/${identity.workflow_sha}...${mainSha}`);
        if (!['ahead','identical'].includes(lineage?.status) || lineage?.merge_base_commit?.sha!==identity.workflow_sha) {
            throw new Error('BLOCKED integration workflow source is not in retained main history.');
        }
    }
    const candidate=call(`repos/${repository}/commits/${identity.candidate_sha}`);
    if (candidate?.sha!==identity.candidate_sha || candidate.commit?.tree?.sha!==identity.candidate_tree) {
        throw new Error('BLOCKED integrated develop candidate or tree is unavailable or changed.');
    }
    const source=call(`repos/${repository}/commits/${sourceSha}`);
    if (source?.sha!==sourceSha || source.parents?.length!==1 || source.parents[0]?.sha!==developBase) {
        throw new Error('BLOCKED integration source S is not the recorded single-child develop candidate from P.');
    }
    if (authorityMode && source.commit?.tree?.sha!==payload.authority_update.inherited_basic.source_tree) {
        throw new Error('BLOCKED inherited BASIC source tree differs from immutable source S.');
    }
    requireIntegrationBasicProfile(payload.basic_profile,repository,authorityMode?sourceSha:identity.workflow_sha,call);
    if (identity.candidate_sha!==sourceSha) {
        if (candidate.parents?.length!==1 || candidate.parents[0]?.sha!==sourceSha) {
            throw new Error('BLOCKED integrated A is not the recorded generated-only child of source S.');
        }
        const prepared=call(`repos/${repository}/compare/${sourceSha}...${identity.candidate_sha}`);
        const generatedPaths=new Set(['app/runtime/modules.php','app/production-files.json','app/core-manifest.json']);
        if (prepared?.base_commit?.sha!==sourceSha || prepared?.merge_base_commit?.sha!==sourceSha
            || prepared.ahead_by!==1 || prepared.behind_by!==0 || prepared.status!=='ahead'
            || !Array.isArray(prepared.files) || prepared.files.length<1 || prepared.files.length>generatedPaths.size
            || prepared.files.some(file=>!generatedPaths.has(file.filename) || file.status!=='modified')) {
            throw new Error('BLOCKED prepared integration candidate A contains a non-generated source change.');
        }
    }
    const jobs=Array.isArray(adapters.integrationJobs) ? adapters.integrationJobs
        : fetchJobs(repository,identity.run_id,Number(identity.run_attempt),identity.candidate_sha,adapters.execute ?? command,call,'integration');
    const nativeJobs=jobs.filter(job=>requiredOwners.includes(job.name));
    const ownerById=new Map(owners.map(owner=>[owner.id,owner]));
    const nonOwnerJobs=jobs.filter(job=>!requiredOwners.includes(job.name));
    const releaseAudit='Develop integration / Authoritative release audit';
    const auxiliaryNames=['Select squashed source','Prepare integration artifacts','Publish prepared integration candidate','Publish develop integration'];
    const inheritedJobName='Inherited BASIC authority';
    if (authorityMode) auxiliaryNames.push(inheritedJobName);
    else {
        const skippedInherited=nonOwnerJobs.filter(job=>job.name===inheritedJobName);
        if (skippedInherited.length>1 || skippedInherited.some(job=>job.workflow_name!=='Integrate into develop'
            || job.run_id!==Number(identity.run_id) || job.run_attempt!==Number(identity.run_attempt)
            || job.status!=='completed' || job.conclusion!=='skipped')) {
            throw new Error('BLOCKED an ordinary integration must uniquely skip the optional inherited BASIC authority job.');
        }
    }
    const auxiliaryJobs=nonOwnerJobs.filter(job=>auxiliaryNames.includes(job.name));
    if (nativeJobs.length!==requiredOwners.length || ownerById.size!==requiredOwners.length || owners.some(owner=>{
        const matches=jobs.filter(job=>job.id===owner.id);
        return matches.length!==1 || !requiredOwners.includes(matches[0].name)
            || matches[0].workflow_name!=='Integrate into develop' || matches[0].run_id!==Number(identity.run_id)
            || matches[0].run_attempt!==Number(identity.run_attempt) || matches[0].status!==owner.status
            || matches[0].conclusion!==owner.conclusion;
    }) || auxiliaryJobs.length!==auxiliaryNames.length
        || auxiliaryNames.some(name=>auxiliaryJobs.filter(job=>job.name===name).length!==1)
        || auxiliaryJobs.some(job=>job.workflow_name!=='Integrate into develop'
            || job.run_id!==Number(identity.run_id) || job.run_attempt!==Number(identity.run_attempt)
            || job.status!=='completed' || job.conclusion!=='success')
        || nonOwnerJobs.some(job=>job.name===releaseAudit
            ? job.workflow_name!=='Integrate into develop' || job.run_id!==Number(identity.run_id)
                || job.run_attempt!==Number(identity.run_attempt) || job.status!=='completed' || job.conclusion!=='skipped'
            : !auxiliaryNames.includes(job.name) && !(job.name===inheritedJobName && !authorityMode))) {
        throw new Error('BLOCKED integration payload native owners differ from the server job/check inventory.');
    }
    return {candidate_sha:identity.candidate_sha,candidate_tree:identity.candidate_tree,
        workflow_sha:identity.workflow_sha,run_id:identity.run_id,run_attempt:identity.run_attempt};
}

/** Read every leaf identity in an immutable Git tree without trusting a truncated compare response.
 * @param {function(string,string=,(JsonValue|null)=):JsonValue} call Read-only server API adapter.
 * @param {string} repository Exact owner/repository identity.
 * @param {string} treeSha Immutable tree SHA.
 * @returns {Map<string,{mode:string,blob:string}>} Complete leaf identities; malformed, duplicate or truncated rows throw.
 */
function releaseLeafTree(call,repository,treeSha) {
    const response=call(`repos/${repository}/git/trees/${treeSha}?recursive=1`);
    if (response?.sha!==treeSha || response.truncated!==false || !Array.isArray(response.tree)) {
        throw new Error('BLOCKED complete release delta tree is unavailable or truncated.');
    }
    const rows=new Map(),seen=new Set();
    for (const entry of response.tree) {
        if (typeof entry?.path!=='string' || entry.path==='' || seen.has(entry.path)
            || !/^[a-f0-9]{40}$/.test(entry.sha ?? '')
            || !['tree','blob','commit'].includes(entry.type)
            || !['040000','100644','100755','120000','160000'].includes(entry.mode)) {
            throw new Error('BLOCKED malformed or duplicate release tree entry.');
        }
        seen.add(entry.path);
        if (entry.type==='tree') {
            if (entry.mode!=='040000') throw new Error('BLOCKED release tree entry type differs from its mode.');
            continue;
        }
        if ((entry.type==='commit')!==(entry.mode==='160000') || entry.mode==='040000') {
            throw new Error('BLOCKED release leaf type differs from its mode.');
        }
        rows.set(entry.path,{mode:entry.mode,blob:entry.sha});
    }
    return rows;
}

/** Authenticate the BASIC-only release delta payload against provider metadata and exact A..B identities.
 * @param {ReleaseRecord} record Aggregate release record containing exact release delta identity and provider digest.
 * @param {string} version Validated release version.
 * @param {{candidate_sha:string,candidate_tree:string}} integration Verified develop integration candidate identity.
 * @param {{api?:function(string,string=,(JsonValue|null)=):JsonValue,deltaEvidence?:{payload:JsonValue,provider:{artifact_id:number,artifact_digest:string,run_id:string}}}} adapters Authenticated artifact reader and read-only GitHub API adapter.
 * @returns {void} Throws unless the authenticated delta exactly matches A, B, version and the listed path/blob changes.
 */
export function verifyBasicDeltaEvidence(record,version,integration,adapters={}) {
    const evidence=adapters.deltaEvidence;
    const identity=record.delta;
    const payload=evidence?.payload;
    const provider=evidence?.provider;
    const sha=value=>typeof value==='string' && /^[a-f0-9]{40}$/.test(value);
    const digest=value=>typeof value==='string' && /^sha256:[a-f0-9]{64}$/.test(value);
    if (!identity || !payload || !provider || identity.base_sha!==integration.candidate_sha
        || identity.candidate_sha!==record.candidate_sha || identity.profile!=='basic'
        || !Array.isArray(identity.changed_paths) || identity.changed_paths.some(path=>typeof path!=='string')
        || new Set(identity.changed_paths).size!==identity.changed_paths.length
        || !Number.isSafeInteger(identity.artifact_id) || identity.artifact_id<1 || !digest(identity.artifact_sha256)
        || provider.artifact_id!==identity.artifact_id || provider.artifact_digest!==identity.artifact_sha256
        || provider.run_id!==record.run_id) {
        throw new Error('BLOCKED BASIC release delta provider identity is missing or inconsistent.');
    }
    if (payload.schema_version!==1 || payload.base_sha!==identity.base_sha
        || payload.base_tree!==integration.candidate_tree || payload.candidate_sha!==identity.candidate_sha
        || payload.version!==version || payload.profile!=='basic' || payload.successful!==true
        || !sha(payload.candidate_tree) || !digest(payload.basic_report_sha256) || !Array.isArray(payload.changes)) {
        throw new Error('BLOCKED BASIC delta payload does not prove the exact successful A..B release change.');
    }
    if (typeof adapters.api!=='function') throw new Error('BLOCKED release candidate tree reader is unavailable.');
    const candidate=adapters.api(`repos/${record.repository}/commits/${record.candidate_sha}`);
    if (candidate?.sha!==record.candidate_sha || candidate.commit?.tree?.sha!==payload.candidate_tree) {
        throw new Error('BLOCKED BASIC delta candidate tree is not the exact release candidate tree.');
    }
    const changes=payload.changes;
    const paths=changes.map(change=>change?.path);
    if (changes.some(change=>!change || typeof change.path!=='string' || !['A','M'].includes(change.status)
        || !/^[a-f0-9]{40}$/.test(change.old_blob ?? '') || !sha(change.new_blob)
        || (change.status==='M' && (change.old_mode!=='100644' || change.new_mode!=='100644'))
        || (change.status==='A' && (change.old_blob!=='0'.repeat(40) || change.old_mode!=='000000' || change.new_mode!=='100644'))
        || (change.status==='M' && change.old_blob==='0'.repeat(40)))
        || new Set(paths).size!==paths.length || paths.length!==identity.changed_paths.length
        || paths.some((path,index)=>path!==identity.changed_paths[index])) {
        throw new Error('BLOCKED BASIC delta has a delete, mode/type change, duplicate or mismatched raw path/blob entry.');
    }
    const before=releaseLeafTree(adapters.api,record.repository,payload.base_tree);
    const after=releaseLeafTree(adapters.api,record.repository,payload.candidate_tree);
    const actualPaths=[...new Set([...before.keys(),...after.keys()])].filter(path=>
        before.get(path)?.mode!==after.get(path)?.mode || before.get(path)?.blob!==after.get(path)?.blob).sort();
    if (actualPaths.length!==paths.length || actualPaths.some((path,index)=>path!==paths[index])) {
        throw new Error('BLOCKED release delta omits or invents a raw tree change.');
    }
    for (const change of changes) {
        const previous=before.get(change.path),current=after.get(change.path);
        if (change.old_blob!==(previous?.blob ?? '0'.repeat(40)) || change.old_mode!==(previous?.mode ?? '000000')
            || change.new_blob!==current?.blob || change.new_mode!==current?.mode
            || change.status!==(previous?'M':'A')) {
            throw new Error('BLOCKED release delta path/blob/mode differs from its immutable trees.');
        }
    }
    const manualPaths=['docs/PHP_Gallery_Manual.tex','docs/PHP_Gallery_Manual.pdf',
        'docs/PHP_Gallery_Manual_CZ.tex','docs/PHP_Gallery_Manual_CZ.pdf',
        'docs/PHP_Gallery_Manual_DE.tex','docs/PHP_Gallery_Manual_DE.pdf',
        'docs/PHP_Gallery_Manual_SV.tex','docs/PHP_Gallery_Manual_SV.pdf'];
    const fixedPaths=new Set(['app/bootstrap.php','app/core-manifest.json','app/production-files.json',
        'README.md','TESTING.md','DATABASE.md','ARCHITECTURE.md','PATCH_NOTES.md','release-metadata.json',...manualPaths]);
    const originPath='.github/release-origins/v_'+version+'.json';
    const predecessorPath='.github/release-predecessors/v_'+version+'.json';
    const requiredDeltaPaths=['app/bootstrap.php','app/core-manifest.json','PATCH_NOTES.md','release-metadata.json'];
    if (paths.some(path=>!fixedPaths.has(path) && path!==originPath && path!==predecessorPath)
        || requiredDeltaPaths.some(path=>!paths.includes(path))) {
        throw new Error('BLOCKED A..B delta changes an unapproved path or omits a required manual artifact.');
    }
    for (const path of [originPath,predecessorPath]) {
        const addition=changes.find(change=>change.path===path);
        if (!addition || addition.status!=='A' || addition.old_blob!=='0'.repeat(40) || addition.old_mode!=='000000') {
            throw new Error('BLOCKED release origin and predecessor records must be exact-version additions.');
        }
    }
    const marker=changes.find(change=>change.path==='app/bootstrap.php');
    if (!marker || marker.status!=='M' || typeof adapters.api!=='function') {
        throw new Error('BLOCKED CMS_VERSION marker projection cannot be independently verified.');
    }
    const readBlob=shaValue=>{
        const blob=adapters.api(`repos/${record.repository}/git/blobs/${shaValue}`);
        if (blob?.sha!==shaValue || blob.encoding!=='base64' || typeof blob.content!=='string'
            || !Number.isSafeInteger(blob.size) || blob.size<0) {
            throw new Error('BLOCKED CMS_VERSION source blob is unavailable or malformed.');
        }
        const encoded=blob.content.replace(/\s/g,'');
        if (!/^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(encoded)) {
            throw new Error('BLOCKED CMS_VERSION source blob encoding is invalid.');
        }
        const bytes=Buffer.from(encoded,'base64');
        const gitSha=createHash('sha1').update(Buffer.concat([Buffer.from(`blob ${bytes.length}\0`),bytes])).digest('hex');
        if (bytes.length!==blob.size || gitSha!==shaValue) throw new Error('BLOCKED CMS_VERSION source blob bytes differ from the raw Git identity.');
        try { return new TextDecoder('utf-8',{fatal:true}).decode(bytes); }
        catch { throw new Error('BLOCKED CMS_VERSION source blob is not valid UTF-8.'); }
    };
    const oldSource=readBlob(marker.old_blob);
    const newSource=readBlob(marker.new_blob);
    const markerPattern=/const CMS_VERSION = '((?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?)';/g;
    const oldMatches=[...oldSource.matchAll(markerPattern)];
    const newMatches=[...newSource.matchAll(markerPattern)];
    if (oldMatches.length!==1 || newMatches.length!==1 || oldMatches[0][1]===version || newMatches[0][1]!==version
        || oldSource.replace(markerPattern,`const CMS_VERSION = '${version}';`)!==newSource) {
        throw new Error('BLOCKED app/bootstrap.php contains changes beyond the CMS_VERSION literal projection.');
    }
}


/** Enforce coverage owners and exact attempt/candidate binding using server evidence.
 * @param {ReleaseRequest} request Authorized immutable inputs.
 * @param {{path:string,event:string,head_branch:string,head_sha?:string,status:string,conclusion:string|null,run_attempt:number}} run Server workflow attempt metadata; original immutable source head_sha is mandatory when authenticating historical PHP owner names.
 * @param {ReleaseJob[]} jobs Complete server job list for the current attempt.
 * @param {ReleaseRecord} record Downloaded aggregate qualification record.
 * @param {string} repository Expected owner/repository identity.
 * @param {string} currentHead Current release branch head observed from GitHub.
 * @param {{api?:function(string,string=,(JsonValue|null)=):JsonValue,execute?:function(string,string[],(string|null)=):string,integrationJobs?:ReleaseJob[],integrationEvidence?:{payload:JsonValue,provider:{artifact_id:number,artifact_digest:string,run_id:string}},deltaEvidence?:{payload:JsonValue,provider:{artifact_id:number,artifact_digest:string,run_id:string}}}} adapters Authenticated provider artifacts and read-only server evidence readers.
 * @returns {ReleaseJob[]} Empty collection after complete successful qualification; throws for any mandatory failure or missing coverage.
 */
export function verifyQualification(request, run, jobs, record, repository, currentHead, adapters={}) {
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
    if (record.lifecycle_schema!==undefined && ![1,2,3].includes(record.lifecycle_schema)) {
        throw new Error('BLOCKED unsupported release lifecycle schema.');
    }
    if (![record.origin_commit_sha,record.selected_develop_sha,record.initial_main_sha,
        record.previous_stable_sha].every(value => typeof value === 'string' && /^[a-f0-9]{40}$/.test(value))
        || record.source_base!==record.previous_release_candidate_sha
        || record.previous_stable_sha !== record.initial_main_sha
        || ![record.previous_tag_object_sha,record.previous_tree_sha,record.previous_release_candidate_sha,record.origin_blob_sha].every(value=>/^[a-f0-9]{40}$/.test(value ?? ''))
        || typeof record.previous_stable_tag !== 'string'
        || !/^v_(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?$/.test(record.previous_stable_tag)) {
        throw new Error('BLOCKED qualification lacks immutable selected-develop and prior-main provenance.');
    }
    if (record.lifecycle_schema===3) {
        if (record.coverage_schema!==1) throw new Error('BLOCKED unsupported release coverage schema.');
        const integration=verifyIntegrationEvidence(record,repository,adapters);
        verifyBasicDeltaEvidence(record,version,integration,adapters);
        if (record.winapp_changed!==false || typeof record.installer_required!=='boolean') {
            throw new Error('BLOCKED BASIC release delta or installer impact is not bound to integrated develop A.');
        }
        const bootstrapPrefix='Run first-install full release lifecycle / ';
        const selector=jobs.filter(job=>job.name==='Select release coverage owner');
        const bootstrapPlaceholder=jobs.filter(job=>job.name==='Run first-install full release lifecycle');
        const bootstrapChildren=jobs.filter(job=>job.name.startsWith(bootstrapPrefix));
        const bootstrapMatrixOwners=evidenceMatrixOwners(bootstrapChildren,repository,run.head_sha,adapters.api ?? api,false);
        const jobIdentity=job=>Number.isSafeInteger(job.id) && job.id>0
            && job.workflow_name==='Release qualification' && job.run_id===Number(request.runId)
            && job.run_attempt===run.run_attempt;
        const skippedBootstrapSuffixes=new Set(['Prepare release candidate','Complete release matrix','Release qualification gate',
            'Prepare manual release assets','Prepare Windows companion installer','Open qualified release PR',
            ...bootstrapMatrixOwners,'Authoritative release audit']);
        const childLeaf=job=>job.name.slice(bootstrapPrefix.length).split(' / ').at(-1);
        if (selector.length!==1 || !jobIdentity(selector[0]) || selector[0].status!=='completed'
            || selector[0].conclusion!=='success' || bootstrapPlaceholder.length!==1
            || !jobIdentity(bootstrapPlaceholder[0]) || bootstrapPlaceholder[0].status!=='completed'
            || bootstrapPlaceholder[0].conclusion!=='skipped'
            || bootstrapChildren.some(job=>!jobIdentity(job) || job.status!=='completed'
                || job.conclusion!=='skipped' || !skippedBootstrapSuffixes.has(childLeaf(job)))) {
            throw new Error('BLOCKED strict release coverage selector or skipped bootstrap inventory is invalid.');
        }
        const required=['Prepare release candidate','Basic release delta checks',
            'Release qualification gate','Prepare manual release assets','Open qualified release PR'];
        required.push('Prepare Windows companion installer');
        // Validated skipped bootstrap rows are alternative-path metadata, not duplicate strict owners.
        const activeJobs=jobs.filter(job=>job!==bootstrapPlaceholder[0] && !bootstrapChildren.includes(job));
        for (const owner of required) {
            const matches=activeJobs.filter(job=>job.name===owner || job.name.endsWith(` / ${owner}`));
            if (matches.length!==1 || !jobIdentity(matches[0]) || matches[0].status!=='completed') {
                throw new Error(`BLOCKED missing/ambiguous required BASIC release job: ${owner}`);
            }
        }
        const installer=activeJobs.find(job=>job.name==='Prepare Windows companion installer');
        if (installer.conclusion!==(record.installer_required?'success':'skipped')) {
            throw new Error('BLOCKED Windows companion installer outcome differs from the strict release policy.');
        }
        const fullOnly=['Positive production package (ubuntu-24.04)','Positive production package (windows-2025)',
            'Positive production package (macos-latest)','PHP 8.5 workflows (mysql:8.4, Unicode)',
            'PHP 8.5 workflows (mariadb:10.11, Unicode)','PHP 8.5 workflows (mariadb:11.4, Unicode)',
            'PHP 8.1 source (unicode)','PHP 8.5 source (ascii)','Required Chromium fixtures','Authoritative release audit'];
        if (jobs.some(job=>!bootstrapChildren.includes(job)
            && fullOnly.some(owner=>job.name===owner || job.name.endsWith(` / ${owner}`)))) {
            throw new Error('BLOCKED full functional qualification owners must run only on integrated develop A.');
        }
        if (activeJobs.some(job=>!required.includes(job.name) && job.name!=='Select release coverage owner')) {
            throw new Error('BLOCKED unknown strict release qualification job.');
        }
        const prepare=activeJobs.find(job=>job.name==='Prepare release candidate');
        if (prepare.conclusion!=='success') throw new Error('BLOCKED preparation integrity cannot be overridden.');
        const ignoredBootstrapJobs=new Set([bootstrapPlaceholder[0],...bootstrapChildren]);
        const failures=jobs.filter(job=>!ignoredBootstrapJobs.has(job) && job.conclusion!=='success'
            && !(record.installer_required===false && job.name==='Prepare Windows companion installer'
                && job.status==='completed' && job.conclusion==='skipped'));
        if (run.conclusion!=='success' || failures.length) throw new Error('BLOCKED mandatory BASIC release CI is red.');
        return failures;
    }
    const owners = ['Prepare release candidate',
        ...evidenceMatrixOwners(jobs,repository,run.head_sha,adapters.api ?? api,false),
        'Authoritative release audit','Release qualification gate'];
    if (record.lifecycle_schema === 2) {
        owners.push('Prepare manual release assets','Open qualified release PR');
        if (record.winapp_changed === true) owners.push('Prepare Windows companion installer');
        else if (record.winapp_changed !== false) throw new Error('BLOCKED installer impact is unknown.');
    }

    let coverageJobs=jobs;
    if (record.lifecycle_schema===2) {
        const bootstrapPrefix='Run first-install full release lifecycle / ';
        const skippedStrictOwners=new Set(['Prepare release candidate','Basic release delta checks','Release qualification gate',
            'Prepare manual release assets','Prepare Windows companion installer','Open qualified release PR']);
        const bootstrapOnlySkippedNames=['Prepare release candidate','Basic release delta checks','Release qualification gate',
            'Prepare manual release assets','Open qualified release PR'];
        const bootstrapStarted=jobs.some(job=>job.name.startsWith(bootstrapPrefix)
            || job.name==='Select release coverage owner'
            || bootstrapOnlySkippedNames.includes(job.name) && job.status==='completed' && job.conclusion==='skipped');
        if (bootstrapStarted) {
            const runIdentity=job=>Number.isSafeInteger(job.id) && job.id>0
                && job.workflow_name==='Release qualification' && job.run_id===Number(request.runId)
                && job.run_attempt===run.run_attempt;
            const selectors=jobs.filter(job=>job.name==='Select release coverage owner');
            const strictSkipped=jobs.filter(job=>skippedStrictOwners.has(job.name));
            if (selectors.length!==1 || !runIdentity(selectors[0]) || selectors[0].status!=='completed'
                || selectors[0].conclusion!=='success'
                || [...skippedStrictOwners].some(name=>strictSkipped.filter(job=>job.name===name).length!==1)
                || strictSkipped.some(job=>!runIdentity(job) || job.status!=='completed' || job.conclusion!=='skipped')) {
                throw new Error('BLOCKED first-install bootstrap selection or skipped strict-path inventory is incomplete.');
            }
            const bootstrapRows=jobs.filter(job=>job.name.startsWith(bootstrapPrefix));
            const allowedBootstrapRows=new Set([...owners,'Prepare Windows companion installer','Complete release matrix']);
            const logicalName=job=>job.name.slice(bootstrapPrefix.length).split(' / ').at(-1);
            if (!bootstrapRows.length || new Set([...jobs.filter(job=>runIdentity(job)).map(job=>job.id)]).size
                !==jobs.filter(job=>runIdentity(job)).length
                || bootstrapRows.some(job=>!runIdentity(job) || !allowedBootstrapRows.has(logicalName(job)))) {
                throw new Error('BLOCKED first-install bootstrap contains an unknown or foreign nested job.');
            }
            const normalized=bootstrapRows.map(job=>({...job,actual_name:job.name,name:logicalName(job)}));
            const expectedBootstrapOwners=owners;
            if (record.winapp_changed===false) expectedBootstrapOwners.push('Prepare Windows companion installer');
            for (const owner of expectedBootstrapOwners) {
                const matches=normalized.filter(job=>job.name===owner || job.name.endsWith(` / ${owner}`));
                if (matches.length!==1 || !runIdentity(matches[0]) || matches[0].status!=='completed'
                    || matches[0].conclusion!==(owner==='Prepare Windows companion installer' && record.winapp_changed===false
                        ? 'skipped' : 'success')) {
                    throw new Error(`BLOCKED missing, ambiguous or unsuccessful bootstrap-owned release job: ${owner}`);
                }
            }
            const matrixInvocations=normalized.filter(job=>job.name==='Complete release matrix');
            if (matrixInvocations.length>1 || matrixInvocations.some(job=>job.status!=='completed'
                || job.conclusion!=='success')) {
                throw new Error('BLOCKED reusable full release matrix invocation did not complete successfully.');
            }
            const allowedNames=new Set([...expectedBootstrapOwners,'Complete release matrix']);
            if (normalized.some(job=>!allowedNames.has(job.name) && ![...expectedBootstrapOwners].some(owner=>job.name.endsWith(` / ${owner}`)))) {
                throw new Error('BLOCKED first-install bootstrap contains an unexpected nested release job.');
            }
            const unrelated=jobs.filter(job=>!job.name.startsWith(bootstrapPrefix)
                && job.name!=='Select release coverage owner' && !skippedStrictOwners.has(job.name));
            if (unrelated.length) throw new Error('BLOCKED first-install run contains an unexpected unprefixed workflow job.');
            coverageJobs=[...normalized,...jobs.filter(job=>job.name==='Select release coverage owner')];
        }
    }
    for (const owner of owners) {
        const matches = coverageJobs.filter(job => job.name === owner || job.name.endsWith(` / ${owner}`));
        if (matches.length !== 1 || matches[0].status !== 'completed') throw new Error(`BLOCKED missing/ambiguous required job: ${owner}`);
    }
    const prepare = coverageJobs.find(job => job.name === 'Prepare release candidate');
    if (prepare.conclusion !== 'success') throw new Error('BLOCKED preparation integrity cannot be overridden.');
    const failures = coverageJobs.filter(job => job.conclusion !== 'success'
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
 * @param {'candidate'|'release'|'integration'} kind Qualification workflow owner; defaults to release.
 * @returns {ReleaseJob[]} Complete workflow coverage, excluding only successful exact programmatic checks for the requested kind; foreign/unbound rows remain visible and malformed evidence throws. A provider HTTP 5xx permits one complete one-job-page read of the same attempt; permission, missing-resource and data failures never use that fallback.
 */
export function fetchJobs(repository, runId, attempt, candidate, execute=command, call=api, kind='release') {
    if (!/^[\w.-]+\/[\w.-]+$/.test(repository) || !/^[1-9]\d*$/.test(runId)
        || !Number.isSafeInteger(attempt) || attempt<1 || !/^[a-f0-9]{40}$/.test(candidate)) {
        throw new Error('BLOCKED invalid exact job inventory identity.');
    }
    const path=`repos/${repository}/actions/runs/${runId}/attempts/${attempt}/jobs`;
    let response;
    try {
        response=execute('gh',['api',path+'?per_page=100','--paginate','--slurp']);
    } catch (error) {
        if (!(error instanceof Error) || !error.message.startsWith('BLOCKED_API_PROVIDER: ')) throw error;
        // Retained job collections can return HTTP 502 for a large provider page.
        // Type: number. Units: job records per page. Scope: one exact attempt read.
        // Consumers: release/integration evidence verification.
        // Rationale: one job per page avoids the observed large-page provider failure
        // while pagination and the unchanged total-count gate still require all jobs.
        // Read once after HTTP 5xx only; never retry writes or weaken evidence checks.
        response=execute('gh',['api',path+'?per_page=1','--paginate','--slurp']);
    }
    const pages=JSON.parse(response);
    if (!Array.isArray(pages) || !pages.length || pages.some(page=>!page || !Array.isArray(page.jobs))) {
        throw new Error('BLOCKED malformed job inventory pages.');
    }
    const jobs=pages.flatMap(page=>page.jobs);
    if (jobs.some(job=>!job || typeof job.name!=='string' || typeof job.status!=='string')) {
        throw new Error('BLOCKED malformed job inventory fields.');
    }
    if (pages.some(page=>page.total_count!==jobs.length)) throw new Error('BLOCKED incomplete job inventory.');
    return workflowCoverageJobs(jobs,repository,runId,attempt,candidate,kind,call);
}

/** Separate workflow coverage from successful programmatic checks bound to the exact candidate attempt.
 * @param {ReleaseJob[]} jobs Complete server-owned Actions inventory, whose completeness is checked by its reader.
 * @param {string} repository Exact owner/repository.
 * @param {string} runId Immutable qualification run ID.
 * @param {number} attempt Exact server attempt.
 * @param {string} candidate Exact qualified candidate SHA.
 * @param {'candidate'|'release'|'integration'} kind Qualification owner used by current or retained historical evidence.
 * @param {function(string):JsonValue} call Read-only check detail adapter.
 * @returns {ReleaseJob[]} Required workflow rows and all foreign/unbound rows; only independently validated exact successful qualification checks are excluded, and mismatched identities throw.
 */
export function workflowCoverageJobs(jobs,repository,runId,attempt,candidate,kind,call=api) {
    if (!['candidate','release','integration'].includes(kind)) throw new Error('BLOCKED unknown qualification coverage owner.');
    return jobs.filter(job=>{
        const qualificationName=kind==='release'?'Release qualification'
            : kind==='integration'?'Develop integration qualification':'Candidate qualification';
        if (![qualificationName,'Complete required CI matrix'].includes(job.name)
            || !Number.isSafeInteger(job.id) || job.id<1) return true;
        // GitHub includes POST /check-runs rows in the Actions jobs endpoint.
        // Authenticate their exact external binding; display names alone cannot omit coverage.
        const check=call('repos/'+repository+'/check-runs/'+job.id);
        if (check.id!==job.id || check.name!==job.name) throw new Error('BLOCKED job/check identity differs.');
        const binding=kind==='integration'?integrationCheckBinding(check.external_id):qualificationCheckBinding(check.external_id);
        return !(check.external_id===kind+':'+runId+':'+attempt+':'+candidate
            && check.app?.id===15368 && check.head_sha===candidate
            && binding?.runId===runId && binding.attempt===attempt && binding.sha===candidate
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
