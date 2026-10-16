/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/release_qualification_record_test.mjs
 * Module Type: Node Regression Test
 * Purpose: Protect exact lifecycle-schema-3 release record binding for integration and BASIC delta evidence.
 * Responsibilities:
 *   - Verify successful A-to-B identity and provider artifact fields
 *   - Refuse mismatched source bases, artifact IDs, digests and unqualified success
 *   - Preserve red gate records without inventing successful delta evidence
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {mkdtempSync,readFileSync,rmSync,writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {buildReleaseQualificationRecord} from '../.github/scripts/release-qualification-record.mjs';

const root=mkdtempSync(join(tmpdir(),'gallery-release-record-test-'));
try {
    const base='a'.repeat(40),candidate='c'.repeat(40),tree='b'.repeat(40);
    const deltaPath=join(root,'release-delta.json');
    const integration={candidate_sha:base,candidate_tree:tree,workflow_sha:'d'.repeat(40),
        workflow_path:'.github/workflows/develop-integration.yml',run_id:'456',run_attempt:'2',
        check_run_id:1001,artifact_id:2001,artifact_sha256:'sha256:'+'1'.repeat(64)};
    const delta={schema_version:1,base_sha:base,base_tree:tree,candidate_sha:candidate,
        candidate_tree:'e'.repeat(40),version:'1.2.3',changes:[{path:'app/bootstrap.php'}],
        profile:'basic',successful:true,basic_report_sha256:'sha256:'+'2'.repeat(64)};
    writeFileSync(deltaPath,JSON.stringify(delta));
    const env={PROVENANCE_JSON:JSON.stringify({origin_blob_sha:'f'.repeat(40)}),
        INTEGRATION_IDENTITY_JSON:JSON.stringify(integration),CANDIDATE_SHA:candidate,
        SELECTED_DEVELOP_SHA:base,SOURCE_BASE:'9'.repeat(40),RELEASE_VERSION:'1.2.3',
        ORIGIN_COMMIT_SHA:'8'.repeat(40),INITIAL_MAIN_SHA:'7'.repeat(40),
        PREVIOUS_STABLE_TAG:'v_1.1.0',PREVIOUS_STABLE_SHA:'6'.repeat(40),
        GITHUB_RUN_ID:'123',GITHUB_RUN_ATTEMPT:'1',GITHUB_REPOSITORY:'owner/gallery',
        GITHUB_REF_NAME:'release/v_1.2.3',READY:'true',GATE_OUTCOME:'success',
        WINAPP_CHANGED:'false',INSTALLER_REQUIRED:'true',DELTA_ARTIFACT_ID:'3001',
        DELTA_ARTIFACT_SHA256:'sha256:'+'3'.repeat(64),DELTA_EVIDENCE_PATH:deltaPath};
    const qualified=buildReleaseQualificationRecord(env);
    assert.equal(qualified.record.lifecycle_schema,3);
    assert.equal(qualified.record.coverage_schema,1);
    assert.equal(qualified.record.integration.candidate_sha,base);
    assert.deepEqual(qualified.record.delta.changed_paths,['app/bootstrap.php']);
    assert.equal(qualified.record.delta.artifact_id,3001);
    assert.equal(qualified.record.delta.artifact_sha256,env.DELTA_ARTIFACT_SHA256);
    assert.equal(qualified.record.source_base,env.SOURCE_BASE);
    assert.equal(qualified.record.delta.base_sha,env.SELECTED_DEVELOP_SHA);
    assert.equal(qualified.record.winapp_changed,false);
    assert.equal(qualified.record.installer_required,true);
    assert.equal(qualified.releaseCheck.external_id,'release:123:1:'+candidate);
    assert.equal(qualified.matrixCheck.name,'Complete required CI matrix');

    const assembled=buildReleaseQualificationRecord({...env,RELEASE_TRUST_MODEL:'protected-develop',
        INTEGRATION_IDENTITY_JSON:JSON.stringify({candidate_sha:base,candidate_tree:tree,trust:'protected-develop'}),
        DELTA_EVIDENCE_PATH:'',DELTA_ARTIFACT_ID:'',DELTA_ARTIFACT_SHA256:''});
    assert.equal(assembled.record.lifecycle_schema,4);
    assert.equal(assembled.record.source_qualification,'protected-develop-v1');
    assert.equal(assembled.record.delta,null,'release must not invent a BASIC delta after inheriting qualified develop');
    assert.equal(assembled.releaseCheck.conclusion,'success');
    assert.throws(()=>buildReleaseQualificationRecord({...env,RELEASE_TRUST_MODEL:'protected-develop',
        INTEGRATION_IDENTITY_JSON:JSON.stringify({candidate_sha:candidate,candidate_tree:tree,trust:'protected-develop'})}),/BLOCKED/,
        'assembly still binds the exact develop source selected by its origin');

    const rejects=overrides=>assert.throws(()=>buildReleaseQualificationRecord({...env,...overrides}));
    rejects({DELTA_ARTIFACT_ID:'not-an-id'});
    rejects({DELTA_ARTIFACT_SHA256:'sha256:bad'});
    rejects({SELECTED_DEVELOP_SHA:'f'.repeat(40)});
    writeFileSync(deltaPath,JSON.stringify({...delta,base_sha:'f'.repeat(40)}));
    rejects({});
    writeFileSync(deltaPath,JSON.stringify(delta));
    rejects({DELTA_EVIDENCE_PATH:join(root,'missing.json')});

    const red=buildReleaseQualificationRecord({...env,GATE_OUTCOME:'failure',DELTA_ARTIFACT_ID:'',DELTA_ARTIFACT_SHA256:'',
        DELTA_EVIDENCE_PATH:join(root,'missing.json')});
    assert.equal(red.record.automated_result,'failure');
    assert.equal(red.record.delta,null);
    assert.equal(red.releaseCheck.conclusion,'failure');
    assert.equal(red.matrixCheck.conclusion,'failure');
    assert.match(readFileSync(deltaPath,'utf8'),/"successful":true/);
} finally {
    rmSync(root,{recursive:true,force:true});
}

process.stdout.write('PASS exact schema-3 release record and BASIC delta binding\n');
