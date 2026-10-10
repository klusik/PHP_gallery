/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-completion-handoff.mjs
 * Module Type: Automatic Release Handoff
 * Purpose: Start completion at the qualified release ref before its workflow reaches main.
 * Responsibilities:
 *   - Dispatch the already registered workflow with exact server-bound release inputs
 *   - Keep approval waiting separate from the guarded server merge
 *   - Observe one owner approval and continue bounded waits without a second human action
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {api,validateRequest,requireQualificationChecks} from './release-promotion.mjs';
import {qualificationRequest,waitForMergeability,completeRelease} from './release-completion.mjs';
import {requireBotPullRequest,effectiveOwnerReview} from './release-owner-authorization.mjs';

/** Gate-owned candidate, API, command and adapter contracts reused by completion.
 * @typedef {import('./release-completion.mjs').CompletionRecord} HandoffRecord
 */
/** Complete REST transport values narrowed by each consuming guard.
 * @typedef {import('./release-completion.mjs').CompletionApi} HandoffApi
 */
/** Isolated candidate evidence and server/Git adapters shared with completion.
 * @typedef {Parameters<typeof completeRelease>[3]} HandoffAdapters
 */

/** Dispatch completion from qualified Q, reusing an existing active observer when visible.
 * @param {HandoffRecord} record Exact gate-owned qualification record.
 * @param {string} repository Expected repository.
 * @param {number} number Exact bot PR number.
 * @param {HandoffApi} call Server transport; only the final workflow dispatch is a write.
 * @param {string} currentRun Current observer run to exclude during a continuation, or empty for the initial handoff.
 * @returns {string} DISPATCHED or ALREADY_RUNNING; throws on registration, identity or branch drift.
 */
export function dispatchCompletion(record,repository,number,call=api,currentRun='') {
    validateRequest(qualificationRequest(record));
    if (record.repository!==repository || record.ready!==true || record.automated_result!=='success' || record.override!==false) {
        throw new Error('BLOCKED incomplete release handoff record.');
    }
    requireQualificationChecks(call,repository,qualificationRequest(record),record.run_attempt);
    const pr=call('repos/'+repository+'/pulls/'+number);
    requireBotPullRequest(pr,repository,record.candidate_sha,'main');
    if (pr.head.ref!==record.branch || pr.base.repo?.full_name!==repository || pr.state!=='open' && !pr.merged) {
        throw new Error('BLOCKED foreign or closed release handoff PR.');
    }
    const workflow=call('repos/'+repository+'/actions/workflows/release-promotion.yml');
    if (workflow.path!=='.github/workflows/release-promotion.yml' || workflow.state!=='active') {
        throw new Error('BLOCKED completion workflow is not registered and active on GitHub.');
    }
    const runs=call('repos/'+repository+'/actions/workflows/release-promotion.yml/runs?event=workflow_dispatch&branch='
        +encodeURIComponent(record.branch)+'&per_page=100');
    // Historical observers may exceed one page. Only active runs matter; paginate
    // instead of assuming the first hundred completed waits exhaust the inventory.
    const active=[];
    if (!Number.isSafeInteger(runs.total_count) || runs.total_count<0 || !Array.isArray(runs.workflow_runs)) throw new Error('BLOCKED observer inventory unavailable.');
    const pages=Math.ceil(runs.total_count/100);
    for (let page=1;page<=Math.max(1,pages);page++) {
        const listing=page===1?runs:call('repos/'+repository+'/actions/workflows/release-promotion.yml/runs?event=workflow_dispatch&branch='
            +encodeURIComponent(record.branch)+'&per_page=100&page='+page);
        if (listing.total_count!==runs.total_count || !Array.isArray(listing.workflow_runs)
            || listing.workflow_runs.length!==Math.min(100,Math.max(0,runs.total_count-(page-1)*100))) {
            throw new Error('BLOCKED incomplete or changing observer inventory.');
        }
        active.push(...listing.workflow_runs.filter(run=>String(run.id)!==currentRun && run.status!=='completed'
            && run.head_sha===record.candidate_sha && run.head_branch===record.branch
            && run.path==='.github/workflows/release-promotion.yml' && run.event==='workflow_dispatch'));
    }
    if (active.length) return 'ALREADY_RUNNING';
    if (call('repos/'+repository+'/git/ref/heads/'+record.branch).object?.sha!==record.candidate_sha) {
        throw new Error('BLOCKED Q moved before completion dispatch.');
    }
    // workflow_dispatch is an explicit GITHUB_TOKEN event exception. Its ref
    // selects Q's workflow, while the legacy main file supplies registration.
    call('repos/'+repository+'/actions/workflows/release-promotion.yml/dispatches','POST',{
        ref:record.branch,inputs:{mode:'complete',pr_number:String(number),candidate_sha:record.candidate_sha,
            qualification_run:record.run_id,release_branch:record.branch}});
    return 'DISPATCHED';
}

/** Retry a guarded dispatch after temporary transport failure, observing already accepted requests before another write.
 * @param {HandoffRecord} record Exact gate-owned qualification record.
 * @param {string} repository Expected repository.
 * @param {number} number Exact bot PR number.
 * @param {HandoffApi} call Server API reader and final workflow-dispatch transport.
 * @param {string} currentRun Observer to exclude when dispatching its continuation, or empty for initial handoff.
 * @param {function(number):Promise<void>} pause Timer receiving milliseconds between up to three guarded attempts.
 * @returns {Promise<string>} DISPATCHED or ALREADY_RUNNING; persistent failures remain blocked.
 */
export async function retryDispatch(record,repository,number,call=api,currentRun='',pause=milliseconds=>new Promise(resolve=>setTimeout(resolve,milliseconds))) {
    for (let attempt=0;attempt<3;attempt++) {
        try {return dispatchCompletion(record,repository,number,call,currentRun);}
        catch (error) {if (attempt===2) throw error;}
        await pause(10000);
    }
    throw new Error('BLOCKED dispatch retries exhausted.');
}

/** Observe a merged or owner-approved exact-Q PR with bounded read retries, continuing if review is still pending.
 * @param {HandoffRecord} record Exact candidate and predecessor identities.
 * @param {string} repository Expected repository.
 * @param {number} number Exact bot PR number.
 * @param {HandoffApi} call Read-only REST transport.
 * @param {function(number):Promise<void>} pause Timer receiving milliseconds; fixtures inject a nonblocking observer.
 * @param {number} probes Positive number of one-minute observations, defaulting to a four-hour window below the hosted job limit.
 * @returns {Promise<string>} READY for approved/merged, otherwise PENDING; changed or closed PRs fail without writes.
 */
export async function observeApproval(record,repository,number,call=api,pause=milliseconds=>new Promise(resolve=>setTimeout(resolve,milliseconds)),probes=240) {
    if (!Number.isSafeInteger(probes) || probes<1 || probes>240) throw new Error('BLOCKED invalid approval wait bound.');
    for (let attempt=0;attempt<probes;attempt++) {
        for (let retry=0;retry<3;retry++) {
            try {
                const pr=call('repos/'+repository+'/pulls/'+number);
                requireBotPullRequest(pr,repository,record.candidate_sha,'main');
                if (pr.head.ref!==record.branch || pr.base.repo?.full_name!==repository
                    || call('repos/'+repository+'/git/ref/heads/'+record.branch).object?.sha!==record.candidate_sha) {
                    throw new Error('BLOCKED approval observer Q or PR drifted.');
                }
                if (pr.merged) return 'READY';
                if (pr.state!=='open' || pr.base.sha!==record.initial_main_sha
                    || call('repos/'+repository+'/git/ref/heads/main').object?.sha!==record.initial_main_sha) {
                    throw new Error('BLOCKED approval observer main drift or closed PR.');
                }
                requireQualificationChecks(call,repository,qualificationRequest(record),record.run_attempt);
                const reviews=call('repos/'+repository+'/pulls/'+number+'/reviews?per_page=100');
                if (!Array.isArray(reviews) || reviews.length>=100) throw new Error('BLOCKED approval observer review inventory incomplete.');
                if (effectiveOwnerReview(reviews,{...pr,merged_at:new Date().toISOString()},repository.split('/')[0],record.candidate_sha)) return 'READY';
                break;
            } catch (error) {if (retry===2) throw error;}
            await pause(10000);
        }
        await pause(60000);
    }
    return 'PENDING';
}

/** Retry completion with fresh guards after a temporary API, merge/tag interruption or develop transport failure.
 * @param {HandoffRecord} record Fully qualified record.
 * @param {string} repository Exact repository.
 * @param {number} number Exact PR number.
 * @param {HandoffAdapters} adapters Optional isolated REST/Git/evidence readers.
 * @param {function(number):Promise<void>} pause Timer receiving milliseconds between up to three attempts.
 * @returns {Promise<import('./release-completion.mjs').CompletionResult>} Verified tagged result; unsafe states still fail or retain an explicit develop blocker.
 */
export async function retryCompletion(record,repository,number,adapters={},pause=milliseconds=>new Promise(resolve=>setTimeout(resolve,milliseconds))) {
    for (let attempt=0;attempt<3;attempt++) {
        try {
            await waitForMergeability(repository,number,record.candidate_sha,record.initial_main_sha,adapters.api ?? api,pause);
            const result=completeRelease(record,repository,number,adapters);
            if (result.synchronization!=='BLOCKED_DEVELOP_POLICY_OR_RACE' || attempt===2) return result;
        } catch (error) {if (attempt===2) throw error;}
        await pause(10000);
    }
    throw new Error('BLOCKED completion retries exhausted.');
}
