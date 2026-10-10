/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-completion.mjs
 * Module Type: Automatic Release Completion
 * Purpose: Complete a qualified release after its sole human PR approval.
 * Responsibilities:
 *   - Bind PR creation, protected merge and immutable tag to the same qualified Q
 *   - Recheck predecessor, origin, review and effective server controls before writes
 *   - Fast-forward develop only when its exact current head is an ancestor of Q
 *   - Stage an idempotent unpublished GitHub Release draft after verifying the immutable tag
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {readFileSync,writeFileSync,mkdtempSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {resolve,join} from 'node:path';
import {pathToFileURL} from 'node:url';
import {createHash} from 'node:crypto';
import {api,command,output,validateRequest,verifyQualification,fetchJobs,fetchReleases,requireQualificationChecks,requireCurrentMainBase,inspectMergedPromotion} from './release-promotion.mjs';
import {originPath,verifyReleaseOrigin,peelReleaseTag} from './release-origin.mjs';
import {requireBotPullRequest,effectiveOwnerReview,requireOwnerRuleset,qualificationCheckBinding} from './release-owner-authorization.mjs';
import {isAncestor,verifyFastForwardHistory} from './release-reconciliation.mjs';
import {verifyPreparedAssets} from './release-asset-integrity.mjs';

/** Exact qualification, provenance, lifecycle and installer-impact fields owned by the gate.
 * @typedef {import('./release-promotion.mjs').ReleaseRecord} CompletionRecord
 */
/** Recursive transport values; completion independently validates consumed server fields.
 * @typedef {import('./release-promotion.mjs').JsonValue} CompletionJson
 */
/** API transport with literal verb and optional JSON body.
 * @typedef {function(string,string=,(CompletionJson|null)=):CompletionJson} CompletionApi
 */
/** Literal bounded Git and gh command transport with optional UTF-8 stdin.
 * @typedef {function(string,string[],(string|null)=):string} CompletionCommand
 */
/** Permanent merge/approval/tag evidence uploaded alongside manual publication assets.
 * @typedef {CompletionRecord & {schema_version:number,final_main_sha:string,final_tree:string,tag:string,
 * tag_object_sha:string,tag_object_type:string,publication:string,main_policy:{id:number,name:string,branch:string,bypass_inventory:string,current_actor_bypass:string},promotion_pr:{number:number,url:string,author:string,
 * reviewed_sha:string,merge_commit_sha:string,merged_at:string,approval:{id:number,reviewer:string,commit_sha:string,submitted_at:string,url:string|null}}}} CompletionEvidence
 */
/** Tagged release and independently observed synchronization, including permanent publication proof.
 * @typedef {{state:string,tag:string,main_sha:string,candidate_sha:string,synchronization:string,detail:string,evidence:CompletionEvidence,draft_status?:string,draft_url?:string,draft_release_id?:number,draft_error?:string}} CompletionResult
 */

/**
 * Space guarded proposal attempts after temporary PR or dispatch transport failures.
 * Type: number. Units: milliseconds. Scope: the current qualification proposal job.
 * Consumers: the bounded PR creation and automatic completion handoff retry in main.
 * Rationale: ten seconds allows transient GitHub transport/metadata failures to settle before full revalidation.
 */
const RELEASE_PROPOSAL_RETRY_DELAY_MS=10000;

/** Return an ordinary read-only qualification request derived from retained evidence.
 * @param {{run_id:string,candidate_sha:string,branch:string}} record Exact server-bound candidate record.
 * @returns {{runId:string,candidate:string,branch:string,mode:string,override:boolean,reason:string,acceptedFailures:string[],manualReview:string}} No-override verification inputs.
 */
export function qualificationRequest(record) {
    return {runId:record.run_id,candidate:record.candidate_sha,branch:record.branch,mode:'plan',
        override:false,reason:'',acceptedFailures:[],manualReview:''};
}

/** Find the unambiguous latest release check and download its bound qualification artifact.
 * @param {string} repository Exact repository.
 * @param {string} sha Exact Q candidate.
 * @param {CompletionApi} call Server API reader.
 * @param {CompletionCommand} execute Literal command adapter.
 * @returns {CompletionRecord} Parsed record; full qualification and provenance are checked separately.
 */
export function loadQualification(repository,sha,call=api,execute=command) {
    const checks=call('repos/'+repository+'/commits/'+sha+'/check-runs?per_page=100&filter=all');
    if (!Array.isArray(checks.check_runs) || checks.total_count!==checks.check_runs.length || checks.total_count>=100) {
        throw new Error('BLOCKED incomplete qualification check inventory.');
    }
    const candidates=checks.check_runs.filter(check=>check.name==='Release qualification'
        && check.app?.id===15368 && check.head_sha===sha && qualificationCheckBinding(check.external_id)?.kind==='release')
        .sort((a,b)=>b.id-a.id);
    const check=candidates[0];
    const binding=qualificationCheckBinding(check?.external_id);
    if (!binding || check.status!=='completed' || check.conclusion!=='success'
        || candidates.filter(value=>value.id===check.id).length!==1) throw new Error('BLOCKED latest exact release qualification is missing or red.');
    const run=call('repos/'+repository+'/actions/runs/'+binding.runId);
    if (run.run_attempt!==binding.attempt || run.status!=='completed' || run.conclusion!=='success') {
        throw new Error('BLOCKED qualification attempt is unfinished, red or superseded.');
    }
    const directory=mkdtempSync(join(tmpdir(),'gallery-release-record-'));
    try {
        execute('gh',['run','download',binding.runId,'--repo',repository,'--name','release-qualification-record','--dir',directory]);
        const record=JSON.parse(readFileSync(join(directory,'release-candidate.json'),'utf8'));
        if (record.run_id!==binding.runId || record.run_attempt!==String(binding.attempt)
            || record.candidate_sha!==sha) throw new Error('BLOCKED downloaded record differs from exact check identity.');
        return record;
    } finally {rmSync(directory,{recursive:true,force:true});}
}

/** Verify complete successful hosted qualification, exact release head and immutable origin.
 * @param {CompletionRecord} record Retained candidate and predecessor identities.
 * @param {string} repository Expected repository.
 * @param {{api?:CompletionApi,git?:CompletionCommand,jobs?:import('./release-promotion.mjs').ReleaseJob[],evidence?:import('./release-promotion.mjs').PublicationEvidence,publishedMainSha?:string,predecessorRecord?:CompletionRecord,predecessorJobs?:import('./release-promotion.mjs').ReleaseJob[]}} adapters Optional isolated readers and exact predecessor evidence.
 * @returns {void} Throws for any stale source, red coverage, moved predecessor or changed origin.
 */
export function verifyCandidate(record,repository,adapters={}) {
    const call=adapters.api ?? api;
    const git=adapters.git ?? command;
    const request=qualificationRequest(record);
    const run=call('repos/'+repository+'/actions/runs/'+record.run_id);
    const head=call('repos/'+repository+'/git/ref/heads/'+record.branch).object?.sha;
    verifyQualification(request,run,adapters.jobs ?? fetchJobs(repository,record.run_id,run.run_attempt,record.candidate_sha),record,repository,head);
    requireQualificationChecks(call,repository,request,record.run_attempt);
    const origin=JSON.parse(git('git',['show',record.candidate_sha+':'+originPath(record.version)]));
    const checked=verifyReleaseOrigin(origin,record.version,record.candidate_sha,repository,adapters);
    for (const [key,value] of Object.entries(checked)) {
        if (record[key]!==value) throw new Error('BLOCKED qualified provenance changed: '+key);
    }
}

/** Create or reuse one bot PR only after the preparation gate and assets succeed.
 * @param {CompletionRecord} record Current run's successful exact-Q record.
 * @param {string} repository Expected repository.
 * @param {string} notes Validated target-version patch notes.
 * @param {{api?:CompletionApi,evidence?:import('./release-promotion.mjs').PublicationEvidence,predecessorRecord?:CompletionRecord,predecessorJobs?:import('./release-promotion.mjs').ReleaseJob[]}} adapters Optional isolated readers and exact predecessor evidence.
 * @returns {{number:number,html_url:string}} Exact PR awaiting the sole owner approval, or a verified already merged PR reused for completion recovery.
 */
export function openQualifiedPullRequest(record,repository,notes,adapters={}) {
    const call=adapters.api ?? api;
    if (record.ready!==true || record.automated_result!=='success' || record.override!==false
        || record.repository!==repository || !notes.trim()) throw new Error('BLOCKED incomplete release gate or patch notes.');
    const request=qualificationRequest(record);
    if (validateRequest(request)!==record.version) throw new Error('BLOCKED record version differs from release branch.');
    requireQualificationChecks(call,repository,request,record.run_attempt);
    if (call('repos/'+repository+'/git/ref/heads/'+record.branch).object?.sha!==record.candidate_sha) {
        throw new Error('BLOCKED stale Q before PR creation.');
    }
    const pulls=call('repos/'+repository+'/pulls?state=all&base=main&head='
        +encodeURIComponent(repository.split('/')[0]+':'+record.branch)+'&per_page=100');
    if (!Array.isArray(pulls) || pulls.length>1) throw new Error('BLOCKED ambiguous release PR inventory.');
    let pr=pulls[0];
    if (pr) pr=call('repos/'+repository+'/pulls/'+pr.number);
    if (pr && pr.state!=='open' && !pr.merged_at) throw new Error('BLOCKED release PR was closed without merge.');
    if (pr?.merged) {
        requireBotPullRequest(pr,repository,record.candidate_sha,'main');
        const observed=inspectMergedPromotion(request,repository,{...adapters,api:call,record});
        if (observed.pr.number!==pr.number) throw new Error('BLOCKED merged release PR identity changed.');
        return {number:pr.number,html_url:pr.html_url};
    }
    const base=requireCurrentMainBase(request,repository,{...adapters,api:call,record});
    requireOwnerRuleset(call,repository,'main','Reviewed release promotion to main','Release qualification');
    if (!pr) pr=call('repos/'+repository+'/pulls','POST',{title:'Release v_'+record.version,head:record.branch,base:'main',
        maintainer_can_modify:false,body:'Qualified head Q: `'+record.candidate_sha+'`\n\n'
            +'[Complete qualification and manual publication assets](https://github.com/'+repository+'/actions/runs/'+record.run_id+')\n\n'
            +'Approve this exact head once. Automatically dispatched completion preserves the protected standard merge, immutable tag and safe develop FF. '
            +'GitHub Release publication remains manual.\n\n'+notes+'\n\nRefs #101, #133'});
    requireBotPullRequest(pr,repository,record.candidate_sha,'main');
    if (pr.base?.sha!==base || requireCurrentMainBase(request,repository,{...adapters,api:call,record})!==base
        || call('repos/'+repository+'/git/ref/heads/'+record.branch).object?.sha!==record.candidate_sha) {
        throw new Error('BLOCKED main or Q changed during PR creation.');
    }
    return {number:pr.number,html_url:pr.html_url};
}

/** Wait briefly for GitHub's asynchronous test merge without accepting changed P or Q.
 * @param {string} repository Exact repository.
 * @param {number} number Exact server PR number.
 * @param {string} candidate Immutable qualified Q SHA.
 * @param {string} previousMain Expected first parent P.
 * @param {CompletionApi} call Read-only server adapter.
 * @param {function(number):Promise<void>} pause Delay adapter receiving milliseconds; the default uses a timer.
 * @returns {Promise<void>} Resolves for an available preview or already merged PR; rejects conflicts, drift or a bounded unavailable preview.
 */
export async function waitForMergeability(repository,number,candidate,previousMain,call=api,pause=milliseconds=>new Promise(resolve=>setTimeout(resolve,milliseconds))) {
    // GitHub computes the test merge asynchronously. Thirty two-second waits
    // cover short metadata propagation without adding a second human action.
    for (let attempt=0;attempt<30;attempt++) {
        const pr=call('repos/'+repository+'/pulls/'+number);
        requireBotPullRequest(pr,repository,candidate,'main');
        if (pr.merged) return;
        if (pr.state!=='open' || pr.base.sha!==previousMain
            || call('repos/'+repository+'/git/ref/heads/main').object?.sha!==previousMain) {
            throw new Error('BLOCKED main or PR changed while waiting for merge preview.');
        }
        if (pr.mergeable===false) throw new Error('BLOCKED release PR has merge conflicts.');
        if (pr.mergeable===true && /^[a-f0-9]{40}$/.test(pr.merge_commit_sha ?? '')) return;
        await pause(2000);
    }
    throw new Error('BLOCKED GitHub merge preview remained unavailable within the bounded wait.');
}

/** Complete one reviewed release using protected merge and a create-only immutable tag.
 * @param {CompletionRecord} record Fully qualified exact-Q identities.
 * @param {string} repository Expected repository.
 * @param {number} number Server PR number derived from the review event.
 * @param {{api?:CompletionApi,git?:CompletionCommand,jobs?:import('./release-promotion.mjs').ReleaseJob[],evidence?:import('./release-promotion.mjs').PublicationEvidence,predecessorRecord?:CompletionRecord,predecessorJobs?:import('./release-promotion.mjs').ReleaseJob[]}} adapters Optional isolated server, Git and bound predecessor readers.
 * @returns {CompletionResult} Completion with independently reported develop synchronization and immutable manual-publication evidence.
 */
export function completeRelease(record,repository,number,adapters={}) {
    const call=adapters.api ?? api;
    const git=adapters.git ?? command;
    let pr=call('repos/'+repository+'/pulls/'+number);
    requireBotPullRequest(pr,repository,record.candidate_sha,'main');
    if (pr.head.ref!==record.branch || pr.base.repo?.full_name!==repository) throw new Error('BLOCKED foreign PR identity.');
    const request=qualificationRequest(record);
    const main=call('repos/'+repository+'/git/ref/heads/main').object?.sha;
    const target=call('repos/'+repository+'/git/matching-refs/tags/v_'+record.version);
    if (!Array.isArray(target)) throw new Error('BLOCKED target tag inventory unavailable.');
    if (!pr.merged && target.some(ref=>ref.ref==='refs/tags/v_'+record.version)) {
        throw new Error('BLOCKED conflicting target tag exists before merge.');
    }
    verifyCandidate(record,repository,{...adapters,publishedMainSha:pr.merged ? pr.merge_commit_sha : undefined});
    const mainPolicy=requireOwnerRuleset(call,repository,'main','Reviewed release promotion to main','Release qualification');
    const reviews=call('repos/'+repository+'/pulls/'+number+'/reviews?per_page=100');
    const approval=effectiveOwnerReview(reviews,{...pr,merged_at:pr.merged_at ?? new Date().toISOString()},repository.split('/')[0],record.candidate_sha);
    if (!approval) throw new Error('BLOCKED sole human owner approval of exact Q is missing.');
    if (!pr.merged) {
        if (pr.state!=='open' || pr.base.sha!==record.initial_main_sha || main!==record.initial_main_sha
            || !pr.mergeable || !/^[a-f0-9]{40}$/.test(pr.merge_commit_sha ?? '')) throw new Error('BLOCKED main drift or release is not mergeable.');
        const preview=call('repos/'+repository+'/commits/'+pr.merge_commit_sha);
        const candidate=call('repos/'+repository+'/commits/'+record.candidate_sha);
        if (preview.parents?.length!==2 || preview.parents[0].sha!==record.initial_main_sha
            || preview.parents[1].sha!==record.candidate_sha || preview.commit.tree.sha!==candidate.commit.tree.sha) {
            throw new Error('BLOCKED GitHub test merge does not preserve [P,Q] and tree(Q).');
        }
        requireCurrentMainBase(request,repository,{...adapters,api:call,record});
        requireQualificationChecks(call,repository,request,record.run_attempt);
        if (call('repos/'+repository+'/git/ref/heads/'+record.branch).object?.sha!==record.candidate_sha) throw new Error('BLOCKED Q advanced before merge.');
        const merged=call('repos/'+repository+'/pulls/'+number+'/merge','PUT',{sha:record.candidate_sha,merge_method:'merge',
            commit_title:'Release v_'+record.version});
        if (merged.merged!==true || !/^[a-f0-9]{40}$/.test(merged.sha ?? '')) throw new Error('BLOCKED server refused protected merge.');
        pr=call('repos/'+repository+'/pulls/'+number);
        if (pr.merge_commit_sha!==merged.sha) throw new Error('BLOCKED merge result differs from PR detail.');
    }
    const observed=inspectMergedPromotion(request,repository,{...adapters,api:call,record});
    if (observed.pr.number!==number) throw new Error('BLOCKED merged PR identity changed.');
    verifyCandidate(record,repository,{...adapters,publishedMainSha:observed.main.sha});
    const tag='v_'+record.version;
    const tags=call('repos/'+repository+'/git/matching-refs/tags/'+tag);
    if (!Array.isArray(tags)) throw new Error('BLOCKED immutable tag inventory unavailable.');
    const existing=tags.find(ref=>ref.ref==='refs/tags/'+tag);
    if (existing) {
        if (peelReleaseTag(call,repository,tag).commit_sha!==observed.main.sha) throw new Error('BLOCKED conflicting immutable release tag.');
    } else {
        // Recheck all mutable identities immediately before the create-only tag operation.
        inspectMergedPromotion(request,repository,{...adapters,api:call,record});
        requireQualificationChecks(call,repository,request,record.run_attempt);
        if (call('repos/'+repository+'/git/ref/heads/'+record.branch).object?.sha!==record.candidate_sha) {
            throw new Error('BLOCKED Q changed between merge and tag.');
        }
        call('repos/'+repository+'/git/refs','POST',{ref:'refs/tags/'+tag,sha:observed.main.sha});
    }
    const identity=peelReleaseTag(call,repository,tag);
    if (identity.commit_sha!==observed.main.sha) throw new Error('BLOCKED tag differs after creation.');
    const evidence={...record,schema_version:1,final_main_sha:observed.main.sha,final_tree:observed.main.commit.tree.sha,
        tag,tag_object_sha:identity.tag_object_sha,tag_object_type:identity.tag_object_type,publication:'MANUAL_PENDING',
        main_policy:mainPolicy,
        promotion_pr:{number:observed.pr.number,url:observed.pr.html_url,author:observed.pr.user.login,
            reviewed_sha:record.candidate_sha,merge_commit_sha:observed.pr.merge_commit_sha,
            merged_at:observed.pr.merged_at,approval:observed.ownerReview}};
    const result={state:'TAGGED',tag,main_sha:observed.main.sha,candidate_sha:record.candidate_sha,synchronization:'PENDING',detail:'',evidence};
    // Tag completion remains valid if a concurrent feature prevents the independent develop FF.
    try {
        requireOwnerRuleset(call,repository,'develop','Reviewed main-to-develop release reconciliation','Complete required CI matrix');
        const develop=call('repos/'+repository+'/git/ref/heads/develop').object?.sha;
        if (develop===record.candidate_sha) result.synchronization='RECONCILED_FF';
        else if (!isAncestor(call,repository,develop,record.candidate_sha)) {
            result.synchronization='BLOCKED_DEVELOP_ADVANCED';result.detail='Current develop '+develop+' is not an ancestor of qualified Q; no merge or rebase was attempted.';
        } else {
            git('git',['fetch','origin','refs/heads/develop']);
            verifyFastForwardHistory(record.selected_develop_sha,develop,record.candidate_sha,git);
            requireQualificationChecks(call,repository,request,record.run_attempt);
            if (call('repos/'+repository+'/git/ref/heads/develop').object?.sha!==develop) throw new Error('BLOCKED develop raced before FF.');
            // Server enforces its required check on Q and rejects every non-fast-forward update.
            git('git',['push','origin',record.candidate_sha+':refs/heads/develop']);
            if (call('repos/'+repository+'/git/ref/heads/develop').object?.sha!==record.candidate_sha) throw new Error('BLOCKED develop differs after FF.');
            result.synchronization='RECONCILED_FF';
        }
    } catch (error) {result.synchronization='BLOCKED_DEVELOP_POLICY_OR_RACE';result.detail=error.message;}
    return result;
}


/** Completion validators with two additional draft-only source/inventory adapters.
 * @typedef {Parameters<typeof completeRelease>[3] & {execute?:CompletionCommand,readPatchNotes?:()=>string}} DraftAdapters
 */
/** Stage one unpublished Release after rechecking exact-Q qualification, reviewed M and immutable tag.
 * @param {CompletionRecord} record Exact qualified release candidate.
 * @param {string} repository Expected owner/repository identity.
 * @param {CompletionResult} result Verified TAGGED completion and its immutable evidence.
 * @param {DraftAdapters} adapters Isolated API, Git, pagination and exact-Q source adapters.
 * @returns {{status:'DRAFT_CREATED'|'DRAFT_ALREADY_EXISTS'|'ALREADY_PUBLISHED',release_id:number,url:string}} No publication or asset upload is performed.
 */
export function prepareDraftRelease(record,repository,result,adapters={}) {
    const call=adapters.api ?? api;
    const git=adapters.git ?? command;
    const tag='v_'+record.version;
    if (result.state!=='TAGGED' || record.repository!==repository
        || validateRequest(qualificationRequest(record))!==record.version
        || result.tag!==tag || result.candidate_sha!==record.candidate_sha
        || !/^[a-f0-9]{40}$/.test(result.main_sha)
        || result.evidence?.final_main_sha!==result.main_sha
        || result.evidence?.tag!==tag || result.evidence?.candidate_sha!==record.candidate_sha
        || result.evidence?.promotion_pr?.reviewed_sha!==record.candidate_sha) {
        throw new Error('BLOCKED draft requires a matching TAGGED exact-Q completion.');
    }
    verifyCandidate(record,repository,{...adapters,publishedMainSha:result.main_sha});
    const observed=inspectMergedPromotion(qualificationRequest(record),repository,{...adapters,api:call,record});
    if (observed.main.sha!==result.main_sha || observed.pr.merge_commit_sha!==result.main_sha
        || observed.main.commit.tree.sha!==result.evidence.final_tree
        || observed.pr.number!==result.evidence.promotion_pr.number) {
        throw new Error('BLOCKED draft merge, tree or owner-reviewed PR differs from completion evidence.');
    }
    if (peelReleaseTag(call,repository,tag).commit_sha!==result.main_sha) {
        throw new Error('BLOCKED draft tag is missing or does not resolve to verified M.');
    }
    const releases=fetchReleases(repository,adapters.execute ?? command);
    const matches=releases.filter(release=>release.tag_name===tag);
    if (matches.length>1 || releases.some(release=>release.tag_name!==tag && release.name==='Version '+record.version)) {
        throw new Error('BLOCKED conflicting release version or duplicate tag identity in complete inventory.');
    }
    const releaseUrl=release=>{
        const prefix='https://github.com/'+repository+'/releases/';
        if (!Number.isSafeInteger(release.id) || release.id<1
            || typeof release.html_url!=='string' || !release.html_url.startsWith(prefix)) {
            throw new Error('BLOCKED release ID or browser URL is unavailable or foreign.');
        }
        return release.draft ? release.html_url.replace('/releases/tag/','/releases/edit/') : release.html_url;
    };
    if (matches.length===1) {
        const listed=matches[0];
        const existing=call('repos/'+repository+'/releases/'+listed.id);
        if (existing.id!==listed.id || existing.tag_name!==tag || existing.draft!==listed.draft
            || existing.prerelease!==false || !Array.isArray(existing.assets)) {
            throw new Error('BLOCKED existing release identity changed or is a conflicting prerelease.');
        }
        return {status:existing.draft?'DRAFT_ALREADY_EXISTS':'ALREADY_PUBLISHED',
            release_id:existing.id,url:releaseUrl(existing)};
    }

    // release-assets.mjs already uses this exact section of PATCH_NOTES.md to write release-notes.md.
    // Read the unchanged checkout of Q rather than regenerating notes or downloading large ZIP/PDF assets.
    if (git('git',['rev-parse','HEAD'])!==record.candidate_sha
        || git('git',['hash-object','PATCH_NOTES.md'])!==git('git',['rev-parse',record.candidate_sha+':PATCH_NOTES.md'])) {
        throw new Error('BLOCKED draft source PATCH_NOTES.md does not exactly match qualified Q.');
    }
    const source=(adapters.readPatchNotes ?? (()=>readFileSync(resolve('PATCH_NOTES.md'),'utf8')))();
    const heading='## Version '+record.version;
    const start=source.indexOf(heading);
    const end=source.indexOf('\n## Version ',start+1);
    const notes=start<0?'':source.slice(start,end<0?source.length:end);
    if (!notes.startsWith(heading+'\n') && !notes.startsWith(heading+'\r\n')) {
        throw new Error('BLOCKED exact version heading is absent from qualified release notes.');
    }
    if (!notes.trim() || source.indexOf(heading,start+heading.length)!==-1) {
        throw new Error('BLOCKED duplicate or empty exact-version release notes.');
    }
    let created;
    try {
        created=call('repos/'+repository+'/releases','POST',{
            tag_name:tag,target_commitish:result.main_sha,name:'Version '+record.version,
            body:notes,draft:true,prerelease:false,generate_release_notes:false});
    } catch (error) {
        throw new Error('Draft create API failed for '+tag+' on verified M '+result.main_sha+': '+error.message,{cause:error});
    }
    if (!Number.isSafeInteger(created?.id) || created.id<1) {
        throw new Error('Draft create API did not return a valid release ID for '+tag+'.');
    }
    const confirmed=call('repos/'+repository+'/releases/'+created.id);
    if (confirmed.id!==created.id || confirmed.tag_name!==tag || confirmed.name!=='Version '+record.version
        || confirmed.body!==notes || confirmed.draft!==true || confirmed.prerelease!==false
        || !Array.isArray(confirmed.assets) || confirmed.assets.length!==0
        || peelReleaseTag(call,repository,tag).commit_sha!==result.main_sha) {
        throw new Error('BLOCKED newly created draft, notes, assets or immutable tag failed verification.');
    }
    return {status:'DRAFT_CREATED',release_id:confirmed.id,url:releaseUrl(confirmed)};
}

/** Create and automatically dispatch a qualified PR or complete its server-bound approval and retain evidence.
 * @returns {Promise<void>} Opens/dispatches a PR, waits for approval, or completes verified merge/tag/FF and stages an unpublished draft.
 */
export async function main() {
    const repository=process.env.GITHUB_REPOSITORY ?? '';
    const {retryDispatch,observeApproval,retryCompletion}=await import('./release-completion-handoff.mjs');
    if (process.env.RELEASE_ACTION==='open') {
        const record=JSON.parse(readFileSync(resolve(process.env.RELEASE_RECORD),'utf8'));
        const notes=readFileSync(resolve(process.env.RELEASE_NOTES),'utf8');
        verifyPreparedAssets(resolve(process.env.RELEASE_ASSETS),record.candidate_sha,record.source_base);
        for (let attempt=0;attempt<3;attempt++) {
            try {
                const pr=openQualifiedPullRequest(record,repository,notes);
                const handoff=await retryDispatch(record,repository,pr.number);
                output('GITHUB_STEP_SUMMARY','Approve the exact qualified release PR once: '+pr.html_url+'; completion '+handoff+'.');
                return;
            } catch (error) {if (attempt===2) throw error;}
            await new Promise(resolve=>{
                setTimeout(resolve,RELEASE_PROPOSAL_RETRY_DELAY_MS);
            });
        }
    }
    const event=JSON.parse(readFileSync(process.env.GITHUB_EVENT_PATH,'utf8'));
    const dispatched=process.env.GITHUB_EVENT_NAME==='workflow_dispatch';
    const pr=dispatched?api('repos/'+repository+'/pulls/'+process.env.RELEASE_PR_NUMBER):event.pull_request;
    if (!['pull_request_review','pull_request','workflow_dispatch'].includes(process.env.GITHUB_EVENT_NAME)
        || !pr || !Number.isSafeInteger(pr.number)) throw new Error('BLOCKED release PR lifecycle event required.');
    const record=loadQualification(repository,pr.head.sha);
    if (dispatched) {
        if (process.env.GITHUB_REF!=='refs/heads/'+record.branch || process.env.GITHUB_SHA!==record.candidate_sha
            || process.env.CANDIDATE_SHA!==record.candidate_sha || process.env.QUALIFICATION_RUN!==record.run_id) {
            throw new Error('BLOCKED completion dispatch differs from qualified release ref, Q or run.');
        }
        await waitForMergeability(repository,pr.number,record.candidate_sha,record.initial_main_sha);
        const current=api('repos/'+repository+'/pulls/'+pr.number);
        verifyCandidate(record,repository,{publishedMainSha:current.merged?current.merge_commit_sha:undefined});
        if (await observeApproval(record,repository,pr.number)==='PENDING') {
            const handoff=await retryDispatch(record,repository,pr.number,api,process.env.GITHUB_RUN_ID);
            output('GITHUB_STEP_SUMMARY','Exact-Q owner review remains pending; automatic continuation '+handoff+'.');
            return;
        }
    }
    const result=await retryCompletion(record,repository,pr.number);
    let draftError=null;
    try {
        const draft=prepareDraftRelease(record,repository,result);
        result.draft_status=draft.status;
        result.draft_url=draft.url;
        result.draft_release_id=draft.release_id;
    } catch (error) {
        draftError=error;
        result.draft_status='DRAFT_PENDING';
        result.draft_error=error.message;
    } finally {
        // Preserve verified tag/merge evidence even if the optional draft API step fails.
        writeFileSync(resolve(process.env.RUNNER_TEMP,'release-completion.json'),JSON.stringify(result,null,2)+'\n');
        const proof=JSON.stringify(result.evidence,null,2)+'\n';
        writeFileSync(resolve(process.env.RUNNER_TEMP,'release-evidence.json'),proof);
        writeFileSync(resolve(process.env.RUNNER_TEMP,'release-evidence.sha256'),createHash('sha256').update(proof).digest('hex')+'  release-evidence.json\n');
        const lines=['### Release '+result.tag,
            '- Merge: verified ('+result.main_sha+')','- Tag: verified',
            '- Develop reconciliation: '+result.synchronization,
            '- Draft: '+result.draft_status,
            result.draft_url?'- GitHub Release: '+result.draft_url:'',
            result.draft_error?'- Draft error: '+result.draft_error:'',
            '- Publication: '+(result.draft_status==='ALREADY_PUBLISHED'?'already published; unchanged':'awaiting manual Publish release')];
        output('GITHUB_STEP_SUMMARY',lines.filter(Boolean).join('\n'));
    }
    if (draftError) throw new Error('TAGGED / DRAFT_PENDING: '+draftError.message,{cause:draftError});
}
if (process.argv[1] && import.meta.url===pathToFileURL(resolve(process.argv[1])).href) {
    main().catch(error=>{process.stderr.write(error.message+'\n');process.exitCode=1;});
}
