/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/coverage_artifact_fixture.mjs
 * Module Type: Coverage Artifact Fixture
 * Purpose: Build inert ZIP bytes for authenticated hosted-evidence regression scenarios.
 * Responsibilities:
 *   - Share real ZIP headers, CRC values and optional compression across coverage consumers
 *   - Keep synthetic archives in memory without network or repository writes
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {deflateRawSync} from 'node:zlib';
import {parseVerifiedZip} from '../../.github/scripts/artifact-data.mjs';
import {MAINTENANCE_MAIN_BRIDGE} from '../../.github/scripts/release-origin.mjs';

/** Calculate the unsigned ZIP CRC-32 for one fixture entry.
 * @param {Buffer} bytes Complete uncompressed entry bytes.
 * @returns {number} Unsigned 32-bit CRC stored in the ZIP headers.
 */
function coverageFixtureCrc32(bytes) {
    let crc=0xffffffff;
    for (const byte of bytes) {
        crc^=byte;
        for (let bit=0;bit<8;bit++) crc=(crc>>>1)^((crc&1)?0xedb88320:0);
    }
    return (crc^0xffffffff)>>>0;
}

/** Encode ordered fixture entries into a complete ZIP with real headers and checksums.
 * @param {Array<[string,Buffer]>} files Entry names and complete uncompressed bytes; malformed names remain available for negative scenarios.
 * @param {number} method ZIP compression method, defaulting to stored bytes; method 8 uses raw DEFLATE.
 * @param {boolean} descriptor Whether to put sizes and CRC in trailing data descriptors instead of local headers, defaulting to false.
 * @returns {Buffer} Complete in-memory ZIP bytes including the central directory and end record.
 */
export function coverageFixtureZip(files,method=0,descriptor=false) {
    const locals=[],central=[];
    let offset=0;
    for (const [name,bytes] of files) {
        const label=Buffer.from(name),encoded=method===8?deflateRawSync(bytes):bytes;
        const crc=coverageFixtureCrc32(bytes),flags=descriptor?8:0;
        const local=Buffer.alloc(30),entry=Buffer.alloc(46);
        local.writeUInt32LE(0x04034b50);local.writeUInt16LE(20,4);local.writeUInt16LE(flags,6);
        local.writeUInt16LE(method,8);local.writeUInt16LE(label.length,26);
        if (!descriptor) {local.writeUInt32LE(crc,14);local.writeUInt32LE(encoded.length,18);local.writeUInt32LE(bytes.length,22);}
        const tail=descriptor?Buffer.alloc(16):Buffer.alloc(0);
        if (descriptor) {tail.writeUInt32LE(0x08074b50);tail.writeUInt32LE(crc,4);tail.writeUInt32LE(encoded.length,8);tail.writeUInt32LE(bytes.length,12);}
        entry.writeUInt32LE(0x02014b50);entry.writeUInt16LE(20,6);entry.writeUInt16LE(flags,8);
        entry.writeUInt16LE(method,10);entry.writeUInt32LE(crc,16);entry.writeUInt32LE(encoded.length,20);
        entry.writeUInt32LE(bytes.length,24);entry.writeUInt16LE(label.length,28);entry.writeUInt32LE(offset,42);
        const block=Buffer.concat([local,label,encoded,tail]);
        locals.push(block);central.push(Buffer.concat([entry,label]));offset+=block.length;
    }
    const directory=Buffer.concat(central),end=Buffer.alloc(22);
    end.writeUInt32LE(0x06054b50);end.writeUInt16LE(files.length,8);end.writeUInt16LE(files.length,10);
    end.writeUInt32LE(directory.length,12);end.writeUInt32LE(offset,16);
    return Buffer.concat([...locals,directory,end]);
}

/** Reproduce the retained maintenance archive and independent server identities without network access.
 * @returns {{api:function(string):import('../../.github/scripts/release-promotion.mjs').JsonValue,download:function(string,number):Buffer,record:import('../../.github/scripts/release-origin.mjs').OriginRecord,evidence:import('../../.github/scripts/release-promotion.mjs').PublicationEvidence,archive:Buffer}} Exact raw artifact, origin projection, predecessor evidence and isolated read-only adapters.
 */
export function maintenanceBridgeFixture() {
    const pin=MAINTENANCE_MAIN_BRIDGE;
    // Retain the real independently hashed archive for offline tampering and provider-binding cases.
    // Type: string. Units: base64 ZIP bytes. Scope: maintenance bridge regression fixtures.
    // Consumers: parseVerifiedZip and readCoverageArtifact. Rationale: the sealed provider digest cannot be recreated from synthetic evidence.
    const encoded='UEsDBBQACAAIAMmjT10AAAAAAAAAAAAAAAAeAAAAbWFpbnRlbmFuY2UtcXVhbGlmaWNhdGlvbi5qc29uvVfbjts2EH33Vwz0lAK+6C5q3xa7DlIg3RhOtn0oCmFEjmxiJVIhKTtGsP8eUGvvNkVboE69r5wLzzkzQ42+TgACy7fUYbUjY6VWwRVEU3/8IJUIriDoUCpHChWn2V6bh6bV+5mhHqUJRkdDvbbSaXPw7g/tYOXDYvVuVW2wbckcnrw4KiEFOqrsFr1jjphRgoxzkXJRhE3SpCUhS3hZhjmmIct5xPL4r+HOEPn4NOPEszwNI4xqxnge11HYxCJuEgxFHhV1nkQC62O87no00mpV1WjHBCJhaZrwJMswrmOKUl43iFGRFVQ2gtI0ZQ1r6mMCqwfDqaoNKr714Q2hGwwtuKy8RNVJm+rP2uDgttpIdzix/m+XOoPK9tq4Z9FEyIoiSSgroyLjNc+ishBF3KRliIJR3NQlpSw7VmZQlRyLmDAWRmEexix+MaFz1PUuuIL4CFZIV/VGN7IdFTLUkhdrtPa6ldyX+OsEACAYE8cp84DGeIBAYTcGrmknaU8CjhmgN7rTTmoFToOXKzhGvOj53emhR2srqXakTo119+FTtV5+ul/fLW+rj8v1r8t1tbx7+2F9s7w9xfHBGFKuQu60qZ6y+FhFOzLBBOBxpKLQyR1Veq/IePvvY/QTrxOzKMrzLM6ysIyK6cly4reSSpEYicDb+/fvAdUL11FGWMCaUMy0ag+wIUUGHYmZdeho9H7qJ+gNNa3cbF3wfIn3GUbYvmlbciRejFwr7idsHNTADpyTtcFofZz+I40yZlkan0dj5Ydb7sYaioGPReyRP+CG4M1eKqH3dhaHcfbTxSmw7EwK71bA5tFJ8zeDklwLujjgPI0uoPlQD8oNszidh+nlKWRnUrg+vnzjqH1vvTTmIjyzT9b0eZCGBNxsje7k0EEjv/hH3l4ccs5+pLWz59ZGy6W8eFcULLxAY3fItZ216Mi6V6CQ/Jjip++99cCNRFFfRdE8ncL9K70uBTvzw/R3DA72c3vFXhN+GZ7bQ/9SgHAeRa9DIc2zJGLpeRRujiDAPL84P0OHzsgv/wPmCcAf457ToRqwrbjekcHNCO9Ow+r2LRiqB9mKKbR+GGuj95bM4skfkHPqnV/4pyCVdX6NF/CbVNd9v7i/vll8lN2NVoq4m4LDDWgD/VC3kqMf6Dn88vLLAC3hjuxp3o9XLJ43IvC7poVB8S2qDYl5MHmcfANQSwcIjvCdP4MDAACXDAAAUEsBAi0DFAAIAAgAyaNPXY7wnT+DAwAAlwwAAB4AAAAAAAAAAAAgAKSBAAAAAG1haW50ZW5hbmNlLXF1YWxpZmljYXRpb24uanNvblBLBQYAAAAAAQABAEwAAADPAwAAAAA=';
    const archive=Buffer.from(encoded,'base64');
    const proof=JSON.parse(parseVerifiedZip(archive,pin.digest,{allowedNames:['maintenance-qualification.json'],maxBytes:1048576})
        .get('maintenance-qualification.json').toString('utf8'));
    const previous='8edd4ac8da2f6e2892292d4a8b201c5f12c4d5cf',stableTree='51ef5a138ae084f63d2eadfb849c6466bff5566a';
    const record={schema_version:1,version:'0.132.9',release_branch:'release/v_0.132.9',selected_develop_sha:'b'.repeat(40),
        initial_main_sha:pin.main,previous_stable_sha:pin.stable,previous_stable_tag:pin.tag};
    const evidence={repository:pin.repository,version:'0.132.8',final_main_sha:pin.stable,final_tree:stableTree,
        candidate_sha:previous,ready:true,automated_result:'success',override:false,run_id:'77'};
    const pr={number:230,state:'closed',merged:true,user:{login:'github-actions[bot]'},
        head:{sha:pin.repair,repo:{full_name:pin.repository}},base:{ref:'main',sha:pin.stable,repo:{full_name:pin.repository}},
        merge_commit_sha:pin.main,merged_by:{login:'github-actions[bot]'},created_at:'2026-10-15T20:30:30Z',merged_at:'2026-10-15T20:31:56Z'};
    const jobs=proof.native_owners.map(job=>({...job,run_id:Number(pin.run),run_attempt:2,head_sha:pin.transport}));
    const api=path=>{
        const prefix='repos/'+pin.repository;
        if (path===prefix+'/git/ref/tags/'+pin.tag) return {object:{type:'commit',sha:pin.stable}};
        if (path===prefix+'/git/ref/heads/main') return {object:{sha:pin.main}};
        if (path===prefix+'/commits/main') return {sha:pin.main,commit:{tree:{sha:pin.tree}}};
        if (path===prefix+'/releases/tags/'+pin.tag) return {tag_name:pin.tag,draft:false,prerelease:false,assets:[{name:'release-evidence.json'}]};
        if (path===prefix+'/commits/'+pin.stable) return {sha:pin.stable,commit:{tree:{sha:stableTree}},parents:[{sha:'f'.repeat(40)},{sha:previous}]};
        if (path===prefix+'/commits/'+previous) return {sha:previous,commit:{tree:{sha:stableTree}}};
        if (path===prefix+'/commits/'+pin.main) return {sha:pin.main,commit:{tree:{sha:pin.tree}},parents:[{sha:pin.stable},{sha:pin.repair}]};
        if (path===prefix+'/commits/'+pin.repair) return {sha:pin.repair,commit:{tree:{sha:pin.tree}}};
        if (path===prefix+'/pulls/230') return structuredClone(pr);
        if (path===prefix+'/pulls/230/reviews?per_page=100') return [{id:5539494509,user:{login:'klusik'},state:'APPROVED',commit_id:pin.repair,submitted_at:'2026-10-15T20:31:26Z'}];
        if (path===prefix+`/actions/runs/${pin.run}/attempts/2`) return {id:Number(pin.run),run_attempt:2,head_sha:pin.transport,
            head_branch:'feature/ci_main_workflow_qualification',path:'.github/workflows/maintenance-workflow-repair.yml',event:'push',status:'completed',conclusion:'success',
            actor:{login:'klusik'},triggering_actor:{login:'klusik'}};
        if (path===prefix+`/actions/runs/${pin.run}/attempts/2/jobs?per_page=100&page=1`) return {total_count:jobs.length,jobs:structuredClone(jobs)};
        if (path===prefix+`/actions/artifacts/${pin.artifact}`) return {id:pin.artifact,name:'maintenance-qualification-record',expired:false,
            digest:pin.digest,workflow_run:{id:Number(pin.run)},size_in_bytes:archive.length};
        if (path===prefix+`/check-runs/${pin.check}`) return {id:pin.check,app:{id:15368},name:'Release qualification',head_sha:pin.repair,
            status:'completed',conclusion:'success',external_id:`maintenance:${pin.run}:2:${pin.repair}`};
        for (const [commit,file,sha] of [[pin.transport,'.github/workflows/maintenance-workflow-repair.yml','86b1608293cac4ed7c56314cf4379c06d062643e'],
            [pin.stable,'.github/workflows/gallery-workflows.yml','8aa6760e62da2ec44847002add26a8f4c2cd32da']]) {
            if (path===prefix+`/contents/${file}?ref=${commit}`) return {type:'file',path:file,sha};
        }
        throw new Error('Unexpected maintenance fixture API request: '+path);
    };
    return {api,download:()=>Buffer.from(archive),record,evidence,archive};
}
