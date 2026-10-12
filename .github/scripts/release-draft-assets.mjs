/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-draft-assets.mjs
 * Module Type: Verified Draft Asset Filler
 * Purpose: Attach authenticated qualification bytes to an unpublished tagged release.
 * Responsibilities:
 *   - Bind provider archives to the exact successful run attempt and Q
 *   - Preserve bytes and refuse conflicting assets without deletion or replacement
 *   - Reuse the verified unchanged companion pair from the pinned predecessor
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {spawnSync} from 'node:child_process';
import {createHash} from 'node:crypto';
import {mkdtempSync,writeFileSync,rmSync} from 'node:fs';
import {join} from 'node:path';
import {tmpdir} from 'node:os';
import {api,command} from './release-promotion.mjs';
import {parseVerifiedZip} from './artifact-data.mjs';
import {verifyPreparedAssets} from './release-asset-integrity.mjs';
import {peelReleaseTag} from './release-origin.mjs';

/** Isolated provider, byte transport and source readers for draft filling.
 * @typedef {{api?:typeof api,git?:typeof command,download?:function(string):Buffer,upload?:function(string,number,string,Buffer):void}} AssetAdapters
 */

/** Hash the complete bytes of one original file or archive.
 * @param {Buffer} bytes Complete content.
 * @returns {string} Lowercase SHA-256.
 */
export function assetHash(bytes) {return createHash('sha256').update(bytes).digest('hex');}

/** Download bounded complete binary API responses without text conversion.
 * @param {string} path Authenticated repository endpoint.
 * @returns {Buffer} Original bytes, or a transport failure.
 */
function downloadBytes(path) {
    const response=spawnSync('gh',['api',path,'-H','Accept: application/octet-stream'],
        {encoding:null,timeout:60000,maxBuffer:512*1024*1024,shell:false});
    if (response.error || response.status!==0 || !Buffer.isBuffer(response.stdout)) throw new Error('BLOCKED binary download failed: '+path);
    return response.stdout;
}

/** Upload one original file using a create-only name on GitHub's upload host.
 * @param {string} repository Exact repository.
 * @param {number} releaseId Verified unpublished release ID.
 * @param {string} name Original safe basename.
 * @param {Buffer} bytes Original content.
 * @returns {void} Throws on transport failure; the caller independently inventories possible success.
 */
function uploadBytes(repository,releaseId,name,bytes) {
    const directory=mkdtempSync(join(tmpdir(),'gallery-upload-'));
    try {
        const file=join(directory,name);writeFileSync(file,bytes);
        command('gh',['api','--hostname','uploads.github.com',`repos/${repository}/releases/${releaseId}/assets?name=${encodeURIComponent(name)}`,
            '--method','POST','-H','Content-Type: application/octet-stream','--input',file]);
    } finally {rmSync(directory,{recursive:true,force:true});}
}

/** Read a complete paginated artifact inventory with stable unique IDs.
 * @param {string} repository Exact repository.
 * @param {string} runId Qualified workflow run.
 * @param {typeof api} call Provider adapter.
 * @returns {import('./release-promotion.mjs').JsonValue[]} Complete artifact rows.
 */
function artifactsForRun(repository,runId,call) {
    const rows=[];let total=null;
    for (let page=1;page<=10;page++) {
        const data=call(`repos/${repository}/actions/runs/${runId}/artifacts?per_page=100&page=${page}`);
        if (!Number.isSafeInteger(data.total_count) || data.total_count<0 || data.total_count>1000
            || total!==null && total!==data.total_count || !Array.isArray(data.artifacts)
            || data.artifacts.length!==Math.min(100,data.total_count-rows.length)) throw new Error('BLOCKED incomplete artifact inventory.');
        total=data.total_count;rows.push(...data.artifacts);
        if (rows.length===total) {
            if (new Set(rows.map(row=>row.id)).size!==rows.length) throw new Error('BLOCKED duplicate artifact identity.');
            return rows;
        }
    }
    throw new Error('BLOCKED artifact inventory exceeded bound.');
}

/** Authenticate one artifact created inside the exact successful owning job of this attempt.
 * @param {import('./release-promotion.mjs').ReleaseRecord} record Qualified release identity.
 * @param {string} name Exact artifact name.
 * @param {string} owner Exact artifact-producing job name.
 * @param {string[]} files Complete permitted archive basenames.
 * @param {AssetAdapters} adapters Provider and binary adapters.
 * @returns {Map<string,Buffer>} Original verified entry bytes; no archive extraction occurs.
 */
export function qualificationAssets(record,name,owner,files,adapters={}) {
    const call=adapters.api ?? api,download=adapters.download ?? downloadBytes;
    const root=`repos/${record.repository}/actions/runs/${record.run_id}`;
    const run=call(root+'/attempts/'+record.run_attempt);
    const latest=call(root);
    if (String(run.id)!==record.run_id || String(run.run_attempt)!==record.run_attempt
        || latest.run_attempt!==run.run_attempt || run.status!=='completed' || run.conclusion!=='success'
        || run.repository?.full_name!==record.repository || run.head_branch!==record.branch
        || run.path!=='.github/workflows/release-qualification.yml' || run.event!=='push') throw new Error('BLOCKED foreign or superseded asset run/attempt.');
    const jobs=call(root+'/attempts/'+record.run_attempt+'/jobs?per_page=100');
    if (jobs.total_count!==jobs.jobs?.length || jobs.total_count>=100) throw new Error('BLOCKED incomplete asset job inventory.');
    const owners=jobs.jobs.filter(job=>(job.name===owner || job.name.endsWith(' / '+owner)) && job.status==='completed' && job.conclusion==='success'
        && String(job.run_id)===record.run_id && String(job.run_attempt)===record.run_attempt);
    if (owners.length!==1) throw new Error('BLOCKED missing exact artifact owner: '+owner);
    const matches=artifactsForRun(record.repository,record.run_id,call).filter(row=>row.name===name);
    if (matches.length!==1) throw new Error('BLOCKED missing or ambiguous artifact: '+name);
    const metadata=call(`repos/${record.repository}/actions/artifacts/${matches[0].id}`);
    const created=Date.parse(metadata.created_at),job=owners[0];
    if (metadata.id!==matches[0].id || metadata.name!==name || metadata.expired!==false
        || metadata.workflow_run?.id!==Number(record.run_id) || metadata.workflow_run?.head_branch!==record.branch
        || !Number.isSafeInteger(metadata.size_in_bytes) || metadata.size_in_bytes<22 || metadata.size_in_bytes>512*1024*1024
        || !Number.isFinite(created) || created<Date.parse(job.started_at) || created>Date.parse(job.completed_at)
        || !Number.isFinite(Date.parse(job.started_at)) || !Number.isFinite(Date.parse(job.completed_at))) throw new Error('BLOCKED artifact provenance or attempt window differs: '+name);
    const bytes=download(`repos/${record.repository}/actions/artifacts/${metadata.id}/zip`);
    if (bytes.length!==metadata.size_in_bytes) throw new Error('BLOCKED artifact size differs: '+name);
    const entries=parseVerifiedZip(bytes,metadata.digest,{allowedNames:files,maxBytes:512*1024*1024});
    if (JSON.stringify(call(`repos/${record.repository}/actions/artifacts/${metadata.id}`))!==JSON.stringify(metadata)) throw new Error('BLOCKED artifact changed during download.');
    return entries;
}

/** Validate the original independent WinApp version and installer checksum contract.
 * @param {Map<string,Buffer>} entries Exact metadata/installer pair.
 * @param {string} version Independent version from qualified Q.
 * @returns {void} Throws for foreign version, size, names or bytes.
 */
export function verifyInstallerPair(entries,version) {
    const name=`PHPGalleryUploader-${version}-Setup.exe`;
    const data=JSON.parse(entries.get('winapp-update.json')?.toString('utf8') ?? 'null');
    const item=data?.assets?.[0],bytes=entries.get(name);
    if (!/^\d+\.\d+\.\d+$/.test(version) || entries.size!==2 || !bytes?.length || !Array.isArray(data?.assets) || data.assets.length!==1
        || item.name!==name || item.version!==version || item.size!==bytes.length || item.sha256!==assetHash(bytes)) throw new Error('BLOCKED installer version, name, size or digest differs.');
}

/** Read one complete uploaded asset and validate server digest and byte count.
 * @param {string} repository Exact repository.
 * @param {import('./release-promotion.mjs').JsonValue} asset Server asset row.
 * @param {AssetAdapters} adapters Byte reader.
 * @returns {Buffer} Original uploaded bytes.
 */
function releaseAssetBytes(repository,asset,adapters) {
    if (!Number.isSafeInteger(asset.id) || asset.id<1 || asset.state!=='uploaded'
        || !Number.isSafeInteger(asset.size) || asset.size<1 || asset.size>512*1024*1024
        || !/^sha256:[a-f0-9]{64}$/.test(asset.digest ?? '')) throw new Error('BLOCKED incomplete asset or missing provider digest: '+asset.name);
    const bytes=(adapters.download ?? downloadBytes)(`repos/${repository}/releases/assets/${asset.id}`);
    if (bytes.length!==asset.size || 'sha256:'+assetHash(bytes)!==asset.digest) throw new Error('BLOCKED uploaded digest differs: '+asset.name);
    return bytes;
}

/** Load original prepared files and the new or pinned unchanged WinApp pair.
 * @param {import('./release-promotion.mjs').ReleaseRecord} record Fully verified release qualification.
 * @param {AssetAdapters} adapters Provider, bytes and qualified source readers.
 * @returns {Map<string,Buffer>} Complete verified publication files before completion receipts.
 */
export function loadDraftAssets(record,adapters={}) {
    const call=adapters.api ?? api,git=adapters.git ?? command;
    const directory=mkdtempSync(join(tmpdir(),'gallery-release-assets-'));
    try {
        const names=['production.zip','core-manifest.json','production-files.json','release-metadata.json','release-notes.md',
            'qualification-evidence.zip','SHA256SUMS','final-integrity.json','PHP_Gallery_Manual.pdf','PHP_Gallery_Manual_CZ.pdf','PHP_Gallery_Manual_DE.pdf','PHP_Gallery_Manual_SV.pdf'];
        const entries=qualificationAssets(record,'manual-release-assets','Prepare manual release assets',names,adapters);
        for (const [name,bytes] of entries) writeFileSync(join(directory,name),bytes);
        verifyPreparedAssets(directory,record.candidate_sha,record.source_base);
        const binding=JSON.parse(entries.get('final-integrity.json').toString('utf8'));
        if (binding.repository!==record.repository || binding.version!==record.version
            || binding.run_id!==record.run_id || binding.run_attempt!==record.run_attempt) throw new Error('BLOCKED asset run/attempt/version binding differs.');
        for (const [name,path] of [['release-metadata.json','release-metadata.json'],['core-manifest.json','app/core-manifest.json'],['production-files.json','app/production-files.json']]) {
            if (git('git',['hash-object','--stdin'],entries.get(name).toString('utf8'))!==git('git',['rev-parse',record.candidate_sha+':'+path])) throw new Error('BLOCKED asset does not match qualified Q: '+name);
        }
        const notes=entries.get('release-notes.md').toString('utf8');
        const source=git('git',['show',record.candidate_sha+':PATCH_NOTES.md']);
        const heading='## Version '+record.version,start=source.indexOf(heading),end=source.indexOf('\n## Version ',start+1);
        if (start<0 || !notes.startsWith(heading+'\n') && !notes.startsWith(heading+'\r\n')
            || source.slice(start,end<0?source.length:end).trim()!==notes.trim()) throw new Error('BLOCKED asset notes/version differ from Q.');
        const version=git('git',['show',record.candidate_sha+':winapp/VERSION']);
        const installerName=`PHPGalleryUploader-${version}-Setup.exe`;
        let pair;
        if (record.installer_required===true || record.installer_required===undefined && record.winapp_changed===true) {
            pair=qualificationAssets(record,'manual-winapp-installer','Prepare Windows companion installer',[installerName,'winapp-update.json'],adapters);
        } else if (record.installer_required===false || record.winapp_changed===false) {
            const previous=call(`repos/${record.repository}/releases/tags/${record.previous_stable_tag}`);
            if (previous.draft!==false || previous.prerelease!==false || previous.tag_name!==record.previous_stable_tag
                || peelReleaseTag(call,record.repository,record.previous_stable_tag).commit_sha!==record.previous_stable_sha
                || git('git',['show',record.previous_release_candidate_sha+':winapp/VERSION'])!==version
                || git('git',['diff','--name-only',record.previous_release_candidate_sha,record.candidate_sha,'--','winapp',':!winapp/tests',':!winapp/README.md']).trim()) throw new Error('BLOCKED predecessor companion source/version changed.');
            pair=new Map();
            for (const name of [installerName,'winapp-update.json']) {
                const matches=previous.assets?.filter(asset=>asset.name===name);
                if (matches?.length!==1) throw new Error('BLOCKED predecessor companion asset missing or ambiguous: '+name);
                pair.set(name,releaseAssetBytes(record.repository,matches[0],adapters));
            }
        } else throw new Error('BLOCKED unknown installer policy.');
        verifyInstallerPair(pair,version);
        for (const [name,bytes] of pair) entries.set(name,bytes);
        return entries;
    } finally {rmSync(directory,{recursive:true,force:true});}
}

/** Fill only the verified draft, reconciling interrupted and concurrent create-only uploads.
 * @param {import('./release-promotion.mjs').ReleaseRecord} record Verified exact Q.
 * @param {import('./release-completion.mjs').CompletionResult} completion Verified tagged completion.
 * @param {number} releaseId Exact staged draft ID.
 * @param {Map<string,Buffer>} files Original verified asset bytes including both completion receipts.
 * @param {AssetAdapters} adapters Isolated server and binary transports.
 * @returns {{status:string,count:number}} Verified complete draft status; conflicts never mutate existing files.
 */
export function fillDraftAssets(record,completion,releaseId,files,adapters={}) {
    const call=adapters.api ?? api,repository=record.repository;
    const endpoint=`repos/${repository}/releases/${releaseId}`;
    const required=['production.zip','core-manifest.json','production-files.json','release-metadata.json','release-notes.md',
        'qualification-evidence.zip','SHA256SUMS','final-integrity.json','PHP_Gallery_Manual.pdf','PHP_Gallery_Manual_CZ.pdf',
        'PHP_Gallery_Manual_DE.pdf','PHP_Gallery_Manual_SV.pdf','release-evidence.json','release-evidence.sha256','winapp-update.json'];
    const receipt=JSON.parse(files.get('release-evidence.json')?.toString('utf8') ?? 'null');
    if (required.some(name=>!files.has(name)) || files.size!==16 || receipt?.repository!==record.repository
        || receipt.version!==record.version || receipt.run_id!==record.run_id || receipt.run_attempt!==record.run_attempt
        || receipt.candidate_sha!==record.candidate_sha || receipt.final_main_sha!==completion.main_sha || receipt.tag!==completion.tag
        || files.get('release-evidence.sha256').toString('utf8')!==assetHash(files.get('release-evidence.json'))+'  release-evidence.json\n') throw new Error('BLOCKED incomplete or foreign draft files/completion receipt.');
    const inspect=()=>{
        const release=call(endpoint);
        if (release.id!==releaseId || release.tag_name!==completion.tag || release.prerelease!==false
            || completion.tag!=='v_'+record.version || completion.candidate_sha!==record.candidate_sha
            || peelReleaseTag(call,repository,completion.tag).commit_sha!==completion.main_sha) throw new Error('BLOCKED draft/tag identity changed.');
        if (release.draft!==true) throw new Error('BLOCKED published release is immutable.');
        const rows=[];
        for (let page=1;page<=10;page++) {
            const part=call(endpoint+`/assets?per_page=100&page=${page}`);
            if (!Array.isArray(part)) throw new Error('BLOCKED incomplete release asset inventory.');
            rows.push(...part);if (part.length<100) return rows;
        }
        throw new Error('BLOCKED release asset inventory exceeded bound.');
    };
    const verify=(rows,name,bytes)=>{
        const matches=rows.filter(asset=>asset.name===name);
        if (matches.length>1) throw new Error('BLOCKED duplicate release asset: '+name);
        if (!matches.length) return false;
        if (!releaseAssetBytes(repository,matches[0],adapters).equals(bytes)) throw new Error('BLOCKED conflicting release asset: '+name);
        return true;
    };
    // Check all existing required files before the first mutation, so a conflict cannot be hidden by partial filling.
    const initial=inspect();
    for (const [name,bytes] of files) {
        if (!/^[A-Za-z0-9_.-]+$/.test(name) || !Buffer.isBuffer(bytes) || !bytes.length) throw new Error('BLOCKED unsafe upload file.');
        verify(initial,name,bytes);
    }
    for (const [name,bytes] of files) {
        if (verify(inspect(),name,bytes)) continue;
        let failure;
        try {(adapters.upload ?? uploadBytes)(repository,releaseId,name,bytes);} catch (error) {failure=error;}
        // A lost response or concurrent 422 is accepted only after independently downloading identical complete bytes.
        if (!verify(inspect(),name,bytes)) throw new Error('BLOCKED missing release asset after upload: '+name+(failure?'; '+failure.message:''));
    }
    const final=inspect();
    for (const [name,bytes] of files) if (!verify(final,name,bytes)) throw new Error('BLOCKED incomplete draft: '+name);
    return {status:'DRAFT_READY',count:files.size};
}
