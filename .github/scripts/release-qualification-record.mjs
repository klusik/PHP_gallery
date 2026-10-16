/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-qualification-record.mjs
 * Module Type: Release Qualification Evidence Producer
 * Purpose: Record release assembly from protected develop while preserving historical BASIC-delta record compatibility.
 * Responsibilities:
 *   - Preserve immutable release-origin provenance and lifecycle fields
 *   - Separate new protected-develop assembly identity from historical uploaded delta evidence
 *   - Publish exact release and required-matrix check payloads from one record
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {existsSync,readFileSync,writeFileSync} from 'node:fs';
import {resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

/** Recursive JSON values used by retained workflow evidence.
 * @typedef {null|boolean|number|string|ReleaseJsonValue[]|{[key:string]:ReleaseJsonValue}} ReleaseJsonValue
 */
/** Frozen protected-develop source or immutable historical integration artifact identity.
 * @typedef {{candidate_sha:string,candidate_tree:string,trust:'protected-develop'}|{candidate_sha:string,candidate_tree:string,workflow_sha:string,workflow_path:string,run_id:string,run_attempt:string,check_run_id:number,artifact_id:number,artifact_sha256:string}} ReleaseIntegrationIdentity
 */
/** Qualified release delta and its uploaded provider artifact identity.
 * @typedef {{base_sha:string,candidate_sha:string,profile:string,changed_paths:string[],artifact_id:number,artifact_sha256:string}} ReleaseDeltaIdentity
 */
/** Programmatic check created by the aggregate release gate.
 * @typedef {{name:string,external_id:string,head_sha:string,status:string,conclusion:string,details_url:string,output:{title:string,summary:string}}} ReleaseProgrammaticCheck
 */

/** Build schema-4 protected-develop assembly or a historical schema-3 delta record and exact programmatic checks.
 * @param {NodeJS.ProcessEnv} env Workflow values carrying prepared candidate and evidence identities.
 * @returns {{record:ReleaseJsonValue & {repository:string,branch:string,candidate_sha:string,source_base:string,version:string,origin_commit_sha:string,selected_develop_sha:string,initial_main_sha:string,previous_stable_tag:string,previous_stable_sha:string,run_id:string,run_attempt:string,ready:boolean,automated_result:string,lifecycle_schema:number,coverage_schema:number,integration:ReleaseIntegrationIdentity,delta:ReleaseDeltaIdentity|null,winapp_changed:boolean,installer_required:boolean,manual_review:string,promotion:string,override:boolean},releaseCheck:ReleaseProgrammaticCheck,matrixCheck:ReleaseProgrammaticCheck}} Retained record and matching exact-Q check payloads.
 */
export function buildReleaseQualificationRecord(env=process.env) {
    const read=(name)=>{
        const value=env[name] ?? '';
        if(value==='') throw new Error('BLOCKED required release identity is missing: '+name);
        return value;
    };
    const parse=(name)=>{
        try { return JSON.parse(read(name)); }
        catch { throw new Error('BLOCKED release evidence is malformed: '+name); }
    };
    const bool=(name)=>{
        const value=read(name);
        if(value!=='true' && value!=='false') throw new Error('BLOCKED invalid boolean release output: '+name);
        return value==='true';
    };
    const provenance=parse('PROVENANCE_JSON');
    const integration=parse('INTEGRATION_IDENTITY_JSON');
    if(!provenance || typeof provenance!=='object' || Array.isArray(provenance)
        || !integration || typeof integration!=='object' || Array.isArray(integration)) {
        throw new Error('BLOCKED release provenance or integration identity is not a JSON object.');
    }
    const candidate=read('CANDIDATE_SHA'),selectedDevelop=read('SELECTED_DEVELOP_SHA');
    const trustDevelop=env.RELEASE_TRUST_MODEL==='protected-develop';
    if(!/^[a-f0-9]{40}$/.test(candidate) || !/^[a-f0-9]{40}$/.test(selectedDevelop)
        || integration?.candidate_sha!==selectedDevelop || !/^[a-f0-9]{40}$/.test(integration?.candidate_tree ?? '')
        || (trustDevelop ? integration.trust!=='protected-develop' : integration.workflow_path!=='.github/workflows/develop-integration.yml')) {
        throw new Error('BLOCKED exact selected-develop integration identity does not match release origin A.');
    }
    const artifactId=Number(env.DELTA_ARTIFACT_ID ?? 0);
    const artifactDigest=env.DELTA_ARTIFACT_SHA256 ?? '';
    const deltaPath=env.DELTA_EVIDENCE_PATH ?? '';
    let delta=null;
    if(deltaPath!=='' && existsSync(deltaPath)) {
        const evidence=JSON.parse(readFileSync(deltaPath,'utf8'));
        if(evidence?.profile!=='basic' || evidence.successful!==true
            || evidence.base_sha!==selectedDevelop || evidence.candidate_sha!==candidate
            || evidence.version!==read('RELEASE_VERSION') || !Array.isArray(evidence.changes)
            || evidence.changes.some(change=>!change || typeof change.path!=='string')
            || !Number.isSafeInteger(artifactId) || artifactId<1
            || !/^sha256:[a-f0-9]{64}$/.test(artifactDigest)) {
            throw new Error('BLOCKED BASIC release delta payload or artifact transport is inconsistent.');
        }
        delta={base_sha:evidence.base_sha,candidate_sha:evidence.candidate_sha,profile:evidence.profile,
            changed_paths:evidence.changes.map(change=>change.path),artifact_id:artifactId,artifact_sha256:artifactDigest};
    }
    const result=read('GATE_OUTCOME');
    if(!['success','failure'].includes(result) || (result==='success' && delta===null && !trustDevelop)) {
        throw new Error('BLOCKED successful release gate lacks exact BASIC delta evidence.');
    }
    const runId=read('GITHUB_RUN_ID'),attempt=read('GITHUB_RUN_ATTEMPT');
    if(!/^[1-9]\d*$/.test(runId) || !/^[1-9]\d*$/.test(attempt)) {
        throw new Error('BLOCKED invalid release workflow run identity.');
    }
    const record={...provenance,repository:read('GITHUB_REPOSITORY'),branch:read('GITHUB_REF_NAME'),
        candidate_sha:candidate,source_base:read('SOURCE_BASE'),version:read('RELEASE_VERSION'),
        origin_commit_sha:read('ORIGIN_COMMIT_SHA'),selected_develop_sha:selectedDevelop,
        initial_main_sha:read('INITIAL_MAIN_SHA'),previous_stable_tag:read('PREVIOUS_STABLE_TAG'),
        previous_stable_sha:read('PREVIOUS_STABLE_SHA'),run_id:runId,run_attempt:attempt,
        ready:bool('READY'),automated_result:result,lifecycle_schema:trustDevelop?4:3,coverage_schema:trustDevelop?2:1,
        integration,delta,winapp_changed:bool('WINAPP_CHANGED'),installer_required:bool('INSTALLER_REQUIRED'),
        ...(trustDevelop?{source_qualification:'protected-develop-v1'}:{}),
        manual_review:'PENDING',promotion:'AUTOMATIC_AFTER_REVIEW',override:false};
    const externalId=`release:${runId}:${attempt}:${candidate}`;
    const check={name:'Release qualification',external_id:externalId,head_sha:candidate,status:'completed',
        conclusion:result==='success'?'success':'failure',
        details_url:`https://github.com/${record.repository}/actions/runs/${runId}`,
        output:{title:'Exact prepared release candidate',summary:JSON.stringify(record,null,2)}};
    const matrixCheck={...check,name:'Complete required CI matrix'};
    return {record,releaseCheck:check,matrixCheck};
}

/** Write the exact retained release record and programmatic check payloads.
 * @returns {void} Creates all configured JSON files or throws when required evidence is incomplete.
 */
function main() {
    const {record,releaseCheck,matrixCheck}=buildReleaseQualificationRecord();
    for(const name of ['OUTPUT_RECORD','OUTPUT_CHECK','OUTPUT_MATRIX_CHECK']) {
        if(!process.env[name]) throw new Error('BLOCKED required output path is missing: '+name);
    }
    writeFileSync(process.env.OUTPUT_RECORD,JSON.stringify(record,null,2)+'\n');
    writeFileSync(process.env.OUTPUT_CHECK,JSON.stringify(releaseCheck,null,2)+'\n');
    writeFileSync(process.env.OUTPUT_MATRIX_CHECK,JSON.stringify(matrixCheck,null,2)+'\n');
}

if(process.argv[1] && resolve(process.argv[1])===fileURLToPath(import.meta.url)) main();
