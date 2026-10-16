/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/coverage-artifacts.mjs
 * Module Type: Hosted Coverage Artifact Reader
 * Purpose: Supply authenticated integration and BASIC release evidence to release consumers.
 * Responsibilities:
 *   - Select the latest exact-A integration identity before accepting its result
 *   - Verify provider artifact metadata, complete ZIP bytes and central BASIC report binding
 *   - Keep all evidence reads free of candidate execution and ref writes
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {spawnSync} from 'node:child_process';
import {createHash} from 'node:crypto';
import {api,verifyIntegrationEvidence} from './release-promotion.mjs';
import {integrationCheckBinding} from './release-owner-authorization.mjs';
import {parseVerifiedZip} from './artifact-data.mjs';
import {bindBasicReleaseDelta} from './release-delta.mjs';

/** Recursive server JSON data consumed by the artifact readers.
 * @typedef {null|boolean|number|string|JsonValue[]|{[key:string]:JsonValue}} JsonValue
 */
/** Read-only server and raw-archive adapters used by hermetic fixtures.
 * @typedef {{api?:function(string):JsonValue,download?:function(string,number):Buffer}} ArtifactAdapters
 */
/** Authenticated artifact payload and independent provider metadata.
 * @typedef {{payload:JsonValue,provider:{artifact_id:number,artifact_digest:string,run_id:string}}} ArtifactEvidence
 */

/** Download complete artifact ZIP bytes through the authenticated GitHub CLI transport.
 * @param {string} repository Exact owner/repository identity.
 * @param {number} artifactId Positive provider artifact identity.
 * @returns {Buffer} Complete raw archive; nonzero, timeout or oversized responses throw.
 */
function downloadArchive(repository,artifactId) {
    const result=spawnSync('gh',['api',`repos/${repository}/actions/artifacts/${artifactId}/zip`],
        {encoding:null,timeout:60000,maxBuffer:32*1024*1024,shell:false});
    if (result.error || result.status!==0 || !Buffer.isBuffer(result.stdout)) {
        throw new Error('BLOCKED complete hosted artifact transport failed.');
    }
    return result.stdout;
}

/** Authenticate one exact artifact and parse only its expected JSON files.
 * @param {string} repository Exact owner/repository identity.
 * @param {number} artifactId Positive provider artifact identity.
 * @param {string} runId Exact owning workflow run identity.
 * @param {string} expectedDigest Immutable provider archive SHA-256.
 * @param {string} name Exact artifact name.
 * @param {string[]} files Complete permitted entry names.
 * @param {ArtifactAdapters} adapters Optional isolated provider and raw-byte readers.
 * @returns {{entries:Map<string,Buffer>,provider:{artifact_id:number,artifact_digest:string,run_id:string}}} Verified in-memory bytes and independent provider identity.
 */
export function readCoverageArtifact(repository,artifactId,runId,expectedDigest,name,files,adapters={}) {
    if (!/^[\w.-]+\/[\w.-]+$/.test(repository) || !Number.isSafeInteger(artifactId) || artifactId<1
        || !/^[1-9]\d*$/.test(runId) || !/^sha256:[a-f0-9]{64}$/.test(expectedDigest)) {
        throw new Error('BLOCKED malformed coverage artifact request.');
    }
    const call=adapters.api ?? api;
    const metadata=call(`repos/${repository}/actions/artifacts/${artifactId}`);
    if (metadata?.id!==artifactId || metadata.name!==name || metadata.expired!==false
        || metadata.digest!==expectedDigest || metadata.workflow_run?.id!==Number(runId)
        || !Number.isSafeInteger(metadata.size_in_bytes) || metadata.size_in_bytes<22
        || metadata.size_in_bytes>32*1024*1024) throw new Error('BLOCKED artifact provider metadata differs or expired.');
    const bytes=(adapters.download ?? downloadArchive)(repository,artifactId);
    if (bytes.length!==metadata.size_in_bytes) throw new Error('BLOCKED artifact provider byte count differs.');
    const entries=parseVerifiedZip(bytes,metadata.digest,{allowedNames:files,maxBytes:32*1024*1024});
    const after=call(`repos/${repository}/actions/artifacts/${artifactId}`);
    if (JSON.stringify(after)!==JSON.stringify(metadata)) throw new Error('BLOCKED artifact metadata changed while reading.');
    return {entries,provider:{artifact_id:artifactId,artifact_digest:metadata.digest,run_id:runId}};
}

/** Read complete bounded provider pages for one exact collection.
 * @param {function(string):JsonValue} call Read-only server adapter.
 * @param {string} path Provider collection endpoint without query parameters.
 * @param {string} key Array property in the provider response.
 * @param {string} query Additional literal query string such as filter=all.
 * @returns {JsonValue[]} Complete uniquely identified rows; truncated or changing inventories throw.
 */
function completeCollection(call,path,key,query='') {
    const rows=[],ids=new Set();
    let total=null;
    for (let page=1;page<=5;page++) {
        const data=call(path+`?per_page=100&page=${page}`+(query?'&'+query:''));
        if (!Number.isSafeInteger(data?.total_count) || data.total_count<0 || data.total_count>500
            || total!==null && data.total_count!==total || !Array.isArray(data[key])
            || data[key].length!==Math.min(100,data.total_count-rows.length)) {
            throw new Error('BLOCKED coverage provider collection is incomplete or changed.');
        }
        total=data.total_count;
        for (const row of data[key]) {
            if (!Number.isSafeInteger(row?.id) || row.id<1 || ids.has(row.id)) {
                throw new Error('BLOCKED duplicate or malformed coverage provider row.');
            }
            ids.add(row.id);rows.push(row);
        }
        if (rows.length===total) return rows;
    }
    throw new Error('BLOCKED coverage provider collection exceeds its bound.');
}

/** Load and independently validate the newest retained exact-A integration proof.
 * @param {string} repository Exact owner/repository identity.
 * @param {string} candidate Exact integrated develop A SHA selected by the immutable release origin.
 * @param {ArtifactAdapters} adapters Optional isolated server and archive transports.
 * @returns {{identity:{candidate_sha:string,candidate_tree:string,workflow_sha:string,workflow_path:string,run_id:string,run_attempt:string,check_run_id:number,artifact_id:number,artifact_sha256:string},evidence:ArtifactEvidence}} Verified record identity and authenticated transport; missing or red latest proof throws.
 */
export function loadIntegrationQualification(repository,candidate,adapters={}) {
    if (!/^[\w.-]+\/[\w.-]+$/.test(repository) || !/^[a-f0-9]{40}$/.test(candidate)) {
        throw new Error('BLOCKED malformed integration evidence lookup.');
    }
    const call=adapters.api ?? api;
    const checks=completeCollection(call,`repos/${repository}/commits/${candidate}/check-runs`,'check_runs','filter=all');
    const relevant=checks.filter(check=>check.name==='Develop integration qualification'
        && check.head_sha===candidate && check.app?.id===15368 && integrationCheckBinding(check.external_id)?.sha===candidate)
        .sort((left,right)=>{
            const a=integrationCheckBinding(left.external_id),b=integrationCheckBinding(right.external_id);
            return Number(b.runId)-Number(a.runId) || b.attempt-a.attempt || right.id-left.id;
        });
    const selected=relevant[0],binding=integrationCheckBinding(selected?.external_id);
    if (!binding || selected.status!=='completed' || selected.conclusion!=='success'
        || relevant.filter(check=>check.external_id===selected.external_id).length!==1) {
        throw new Error('BLOCKED newest retained integration qualification is missing, ambiguous or red.');
    }
    const run=call(`repos/${repository}/actions/runs/${binding.runId}`);
    if (run?.run_attempt!==binding.attempt || run.status!=='completed' || run.conclusion!=='success') {
        throw new Error('BLOCKED integration attempt is superseded, unfinished or red.');
    }
    const artifacts=completeCollection(call,`repos/${repository}/actions/runs/${binding.runId}/artifacts`,'artifacts');
    const retained=artifacts.filter(artifact=>artifact.name==='develop-integration-record');
    if (retained.length!==1) throw new Error('BLOCKED integration artifact is missing or ambiguous.');
    const artifact=retained[0];
    const transport=readCoverageArtifact(repository,artifact.id,binding.runId,artifact.digest,
        'develop-integration-record',['develop-integration.json'],adapters);
    const payload=JSON.parse(new TextDecoder('utf-8',{fatal:true}).decode(transport.entries.get('develop-integration.json')));
    const identity={candidate_sha:candidate,candidate_tree:payload.candidate_tree,
        workflow_sha:payload.workflow_sha,workflow_path:payload.workflow_path,run_id:binding.runId,
        run_attempt:String(binding.attempt),check_run_id:selected.id,artifact_id:artifact.id,artifact_sha256:artifact.digest};
    const evidence={payload,provider:transport.provider};
    verifyIntegrationEvidence({lifecycle_schema:3,coverage_schema:1,selected_develop_sha:candidate,integration:identity},
        repository,{...adapters,api:call,integrationEvidence:evidence});
    return {identity,evidence};
}

/** Capture the already qualified protected develop source without reopening historical test evidence.
 * @param {string} repository Exact owner/repository.
 * @param {string} candidate Immutable develop commit selected when starting the release.
 * @param {ArtifactAdapters} [adapters={}] Read-only provider adapter; no archived FULL/BASIC artifacts are required.
 * @returns {{candidate_sha:string,candidate_tree:string,trust:'protected-develop'}} Frozen packaging source identity.
 */
export function loadDevelopSource(repository,candidate,adapters={}) {
    if (!/^[\w.-]+\/[\w.-]+$/.test(repository) || !/^[a-f0-9]{40}$/.test(candidate ?? '')) {
        throw new Error('BLOCKED malformed release source identity.');
    }
    const call=adapters.api ?? api;
    const develop=call(`repos/${repository}/git/ref/heads/develop`)?.object?.sha;
    const ancestry=candidate===develop ? {status:'identical',merge_base_commit:{sha:candidate}}
        : call(`repos/${repository}/compare/${candidate}...${develop}`);
    const commit=call(`repos/${repository}/commits/${candidate}`);
    if (!['ahead','identical'].includes(ancestry?.status) || ancestry.merge_base_commit?.sha!==candidate
        || commit?.sha!==candidate || !/^[a-f0-9]{40}$/.test(commit.commit?.tree?.sha ?? '')) {
        throw new Error('BLOCKED selected release source is not retained on protected develop.');
    }
    return {candidate_sha:candidate,candidate_tree:commit.commit.tree.sha,trust:'protected-develop'};
}

/** Authenticate exact integration and BASIC delta transports for a schema-3 release consumer.
 * @param {import('./release-promotion.mjs').ReleaseRecord} record Exact retained release qualification record.
 * @param {ArtifactAdapters} adapters Optional isolated server and archive transports.
 * @returns {{integrationEvidence?:ArtifactEvidence,deltaEvidence?:ArtifactEvidence}} Empty adapters for legacy schemas; both authenticated payload/provider pairs for schema 3.
 */
export function loadReleaseCoverage(record,adapters={}) {
    if (record.lifecycle_schema!==3) return {};
    const integration=record.integration,delta=record.delta;
    if (!integration || !delta) throw new Error('BLOCKED schema-3 coverage identities are missing.');
    const first=readCoverageArtifact(record.repository,integration.artifact_id,integration.run_id,
        integration.artifact_sha256,'develop-integration-record',['develop-integration.json'],adapters);
    const second=readCoverageArtifact(record.repository,delta.artifact_id,record.run_id,
        delta.artifact_sha256,'release-delta-evidence',['release-delta.json','basic-report.json'],adapters);
    const decode=bytes=>JSON.parse(new TextDecoder('utf-8',{fatal:true}).decode(bytes));
    const payload=decode(second.entries.get('release-delta.json'));
    const report=second.entries.get('basic-report.json');
    if (payload.basic_report_sha256!=='sha256:'+createHash('sha256').update(report).digest('hex')) {
        throw new Error('BLOCKED BASIC report bytes differ from the retained delta proof.');
    }
    bindBasicReleaseDelta(payload,new TextDecoder('utf-8',{fatal:true}).decode(report));
    return {integrationEvidence:{payload:decode(first.entries.get('develop-integration.json')),provider:first.provider},
        deltaEvidence:{payload,provider:second.provider}};
}
