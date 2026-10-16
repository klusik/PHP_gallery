/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/feature-qualification.mjs
 * Module Type: Feature Integration Evidence
 * Purpose: Separate hosted candidate execution from privileged integration and resumable publication.
 * Responsibilities: Authenticate changed test inputs, independent BASIC and complete reusable native FULL evidence.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {isDeepStrictEqual} from 'node:util';
import {readCoverageArtifact} from './coverage-artifacts.mjs';
import {fullMatrixOwners,requireCurrentWorkflowOwners} from './ci-owner-contract.mjs';
import {maintenanceRows} from './maintenance-authority.mjs';
import {bindTrustedBasicReport} from './trusted-basic.mjs';

/** Retained native execution identity, independent of the later publication attempt.
 * @typedef {{run_id:string,run_attempt:string,workflow_sha:string}} FullExecution
 */
/** Independent BASIC evidence plus its original provider archive.
 * @typedef {import('./trusted-basic.mjs').TrustedBasicEvidence & {artifact_id:number,artifact_sha256:string}} FeatureBasic
 */
/** Feature evidence owned by a main dispatcher, with the complete candidate tree as the conservative input closure.
 * @typedef {{schema_version:1,input_tree:string,basic:FeatureBasic,execution:FullExecution}} FeatureQualification
 */

/** Verify an immutable trusted dispatcher is retained in current main history.
 * @param {string} repository Exact owner/repository.
 * @param {string} authority Immutable dispatcher source.
 * @param {function(string):Record<string,unknown>} call Independent provider reader.
 * @returns {void} Throws for unavailable, foreign or rewritten authority.
 */
export function requireMainAuthority(repository,authority,call) {
    if (!/^[a-f0-9]{40}$/.test(authority ?? '')) throw new Error('BLOCKED malformed feature authority.');
    const main=call(`repos/${repository}/git/ref/heads/main`)?.object?.sha;
    const lineage=main===authority ? {status:'identical',merge_base_commit:{sha:authority}}
        : call(`repos/${repository}/compare/${authority}...${main}`);
    if (!/^[a-f0-9]{40}$/.test(main ?? '') || !['ahead','identical'].includes(lineage?.status)
        || lineage.merge_base_commit?.sha!==authority) throw new Error('BLOCKED feature dispatcher is outside retained main history.');
}

/** Reproduce independent BASIC from its complete original archive instead of trusting candidate-authored checks.
 * @param {FeatureBasic} basic Retained independent evaluator record and provider identity.
 * @param {{authority:string,source:string,tree:string,base:string,run:string}} identity Frozen authority, candidate, input tree, comparison base and producing run.
 * @param {string} repository Exact owner/repository.
 * @param {import('./coverage-artifacts.mjs').ArtifactAdapters} adapters Complete provider and raw-archive readers.
 * @returns {void} Returns only for the exact original successful central report and bound source.
 */
export function verifyFeatureBasic(basic,identity,repository,adapters) {
    if (!basic || !Number.isSafeInteger(basic.artifact_id) || basic.artifact_id<1) throw new Error('BLOCKED missing independent feature BASIC.');
    const transport=readCoverageArtifact(repository,basic.artifact_id,identity.run,basic.artifact_sha256,
        'develop-integration-inherited-basic',['trusted-basic.json','trusted-basic.json.report.json'],adapters);
    const bytes=transport.entries.get('trusted-basic.json.report.json');
    const original=JSON.parse(transport.entries.get('trusted-basic.json').toString('utf8'));
    const reproduced=bindTrustedBasicReport(JSON.parse(bytes.toString('utf8')),
        {authority:identity.authority,source:identity.source,tree:identity.tree,base:identity.base},bytes);
    const retained={...basic};delete retained.artifact_id;delete retained.artifact_sha256;
    if (!isDeepStrictEqual(original,reproduced) || !isDeepStrictEqual(retained,reproduced)) {
        throw new Error('BLOCKED independent feature BASIC differs from its original report.');
    }
}

/** Authenticate every required native FULL owner from its actual execution run and immutable dispatcher.
 * @param {string} repository Exact owner/repository.
 * @param {FullExecution} execution Original execution run, attempt and main-owned workflow source.
 * @param {Array<{id:number,name:string,status:string,conclusion:string}>} retained Exact required native owner rows.
 * @param {function(string):Record<string,unknown>} call Read-only provider adapter.
 * @returns {Record<string,unknown>[]} Complete actual job inventory; missing, failed, cancelled, duplicated or superseded execution throws.
 */
export function verifyFeatureOwners(repository,execution,retained,call) {
    if (!execution || !/^[1-9]\d*$/.test(execution.run_id ?? '') || !/^[1-9]\d*$/.test(execution.run_attempt ?? '')) {
        throw new Error('BLOCKED invalid feature execution identity.');
    }
    requireCurrentWorkflowOwners(repository,execution.workflow_sha,call);
    const run=call(`repos/${repository}/actions/runs/${execution.run_id}`);
    // Publication can fail after successful execution; the sealed owner jobs, not aggregate green, qualify execution.
    if (run?.id!==Number(execution.run_id) || run.run_attempt!==Number(execution.run_attempt)
        || run.path!=='.github/workflows/develop-integration.yml' || !/^(?:main|feature\/.+)$/.test(run.head_branch ?? '')
        || run.head_sha!==execution.workflow_sha || run.event!=='workflow_dispatch'
        || !['in_progress','completed'].includes(run.status) || ['cancelled','timed_out'].includes(run.conclusion)) {
        throw new Error('BLOCKED feature execution run is foreign, superseded or cancelled.');
    }
    if (run.head_branch==='main') requireMainAuthority(repository,execution.workflow_sha,call);
    else {
        const owner=repository.split('/')[0];
        if (run.actor?.login!==owner || run.triggering_actor?.login!==owner
            || !['admin','maintain'].includes(call(`repos/${repository}/collaborators/${owner}/permission`)?.permission)) {
            throw new Error('BLOCKED feature FULL was not explicitly selected by its maintainer.');
        }
    }
    const jobs=maintenanceRows(call,`repos/${repository}/actions/runs/${execution.run_id}/attempts/${execution.run_attempt}/jobs`,'jobs');
    const names=fullMatrixOwners().map(name=>'Develop integration / '+name);
    if (!Array.isArray(retained) || retained.length!==names.length || new Set(retained.map(row=>row.id)).size!==names.length) {
        throw new Error('BLOCKED incomplete feature FULL record.');
    }
    for (const name of names) {
        const rows=jobs.filter(job=>job.name===name),proof=retained.filter(job=>job.name===name);
        if (rows.length!==1 || proof.length!==1 || rows[0].id!==proof[0].id || rows[0].status!=='completed'
            || rows[0].conclusion!=='success' || proof[0].status!=='completed' || proof[0].conclusion!=='success'
            || rows[0].run_id!==Number(execution.run_id) || rows[0].run_attempt!==Number(execution.run_attempt)
            || rows[0].workflow_name!=='Integrate into develop') throw new Error('BLOCKED unsuccessful feature FULL owner: '+name);
    }
    return jobs;
}

/** Load a sealed exact-candidate execution before scheduling another expensive FULL.
 * @param {string} repository Exact owner/repository.
 * @param {string} runId Explicit original qualification run.
 * @param {{candidate:string,tree:string,base:string}} expected Immutable candidate, complete input tree and observed develop base.
 * @param {import('./coverage-artifacts.mjs').ArtifactAdapters} adapters Independent provider/archive readers.
 * @returns {{record:Record<string,unknown>,provider:{artifact_id:number,artifact_digest:string,run_id:string}}} Original complete record and provider identity; publication failure alone does not invalidate successful sealed execution.
 */
export function loadFeatureExecution(repository,runId,expected,adapters) {
    if (!/^[1-9]\d*$/.test(runId ?? '')) throw new Error('BLOCKED invalid prior qualification run.');
    const call=adapters.api;
    const artifacts=maintenanceRows(call,`repos/${repository}/actions/runs/${runId}/artifacts`,'artifacts')
        .filter(row=>row.name==='develop-integration-execution');
    if (artifacts.length!==1) throw new Error('BLOCKED sealed feature execution artifact is missing or duplicated.');
    const artifact=artifacts[0];
    const transport=readCoverageArtifact(repository,artifact.id,runId,artifact.digest,
        'develop-integration-execution',['develop-execution.json'],adapters);
    const record=JSON.parse(transport.entries.get('develop-execution.json').toString('utf8'));
    if (record.schema_version!==2 || record.repository!==repository || record.candidate_sha!==expected.candidate
        || record.source_sha!==expected.candidate || record.candidate_tree!==expected.tree
        || record.expected_develop_sha!==expected.base || record.source_base!==expected.base
        || String(record.run_id)!==runId || record.feature_qualification?.schema_version!==1
        || record.feature_qualification.input_tree!==expected.tree) throw new Error('BLOCKED prior execution inputs changed.');
    const jobs=verifyFeatureOwners(repository,record.feature_qualification.execution,record.native_owners,call);
    const run=call(`repos/${repository}/actions/runs/${runId}`);
    if (run.status!=='completed' || run.run_attempt!==Number(record.run_attempt) || run.head_sha!==record.workflow_sha) {
        throw new Error('BLOCKED prior qualification attempt is unfinished or superseded.');
    }
    const sealJobs=record.feature_qualification.execution.run_id===runId ? jobs
        : maintenanceRows(call,`repos/${repository}/actions/runs/${runId}/attempts/${record.run_attempt}/jobs`,'jobs');
    const seal=sealJobs.filter(job=>job.name==='Retain integration execution');
    if (seal.length!==1 || seal[0].status!=='completed' || seal[0].conclusion!=='success') {
        throw new Error('BLOCKED prior execution was not independently sealed.');
    }
    verifyFeatureBasic(record.feature_qualification.basic,{authority:record.workflow_sha,source:expected.candidate,
        tree:expected.tree,base:expected.base,run:runId},repository,adapters);
    return {record,provider:transport.provider};
}
