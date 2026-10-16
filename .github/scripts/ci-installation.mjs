/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/ci-installation.mjs
 * Module Type: Qualified CI Pull Request
 * Purpose: Turn an explicitly selected feature CI qualification into a normal human-reviewed main installation PR.
 * Responsibilities: Require actual complete hosted owners, unchanged branch/base leases and server review protections.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {appendFileSync,writeFileSync,readFileSync} from 'node:fs';
import {resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {api} from './release-promotion.mjs';
import {maintenanceRows} from './maintenance-authority.mjs';
import {fullMatrixOwners} from './ci-owner-contract.mjs';
import {requireOwnerRuleset,requireBotPullRequest} from './release-owner-authorization.mjs';

/** Exact CI-only feature candidate qualified for human-reviewed main installation.
 * @typedef {{schema_version:1,repository:string,branch:string,candidate_sha:string,candidate_tree:string,comparison_base:string,workflow_sha:string,run_id:string,run_attempt:number,native_owners:Array<{id:number,name:string,status:string,conclusion:string}>}} CiInstallation
 */

/** Validate complete actual FULL/release audit evidence and source leases before proposing installation.
 * @param {Record<string,string|undefined>} env Actual owner-selected workflow environment and prepared source outputs.
 * @param {function(string,string=,(Record<string,unknown>|null)=):Record<string,unknown>} call Independent provider adapter.
 * @returns {CiInstallation} Exact source and complete successful native owners; product changes or stale evidence throw.
 */
export function qualifyCiInstallation(env,call) {
    const repository=env.GITHUB_REPOSITORY,owner=repository?.split('/')[0],candidate=env.CANDIDATE_SHA,base=env.SOURCE_BASE;
    if (!/^[\w.-]+\/[\w.-]+$/.test(repository ?? '') || env.GITHUB_EVENT_NAME!=='workflow_dispatch'
        || !/^feature\/[\w./-]+$/.test(env.GITHUB_REF_NAME ?? '') || env.GITHUB_REF_NAME.includes('..')
        || ![candidate,base,env.GITHUB_SHA].every(value=>/^[a-f0-9]{40}$/.test(value ?? ''))
        || env.GITHUB_ACTOR!==owner || !['admin','maintain'].includes(call(`repos/${repository}/collaborators/${owner}/permission`)?.permission)) {
        throw new Error('BLOCKED CI installation requires explicit owner feature dispatch.');
    }
    const run=call(`repos/${repository}/actions/runs/${env.GITHUB_RUN_ID}`);
    if (run?.path!=='.github/workflows/candidate-preparation.yml' || run.head_sha!==env.GITHUB_SHA
        || run.head_branch!==env.GITHUB_REF_NAME || run.status!=='in_progress' || run.event!=='workflow_dispatch'
        || run.run_attempt!==Number(env.GITHUB_RUN_ATTEMPT) || run.triggering_actor?.login!==owner) {
        throw new Error('BLOCKED CI installation is not the exact owner-selected active attempt.');
    }
    if (call(`repos/${repository}/git/ref/heads/main`).object?.sha!==base
        || call(`repos/${repository}/git/ref/heads/${env.GITHUB_REF_NAME}`).object?.sha!==candidate) {
        throw new Error('BLOCKED CI installation source or main changed.');
    }
    const changes=call(`repos/${repository}/compare/${base}...${candidate}`);
    const allowed=path=>/^(?:\.github\/(?:(?:scripts|workflows)\/|AGENTS\.md$|texlive-(?:lock\.json|packages\.txt)$)|tests\/|scripts\/(?:audit[^/]*|source_contracts\/|check_source[^/]*|cli_guard\.php$|runtime_plan_metrics\.php$)|docs\/|AGENTS\.md$|TESTING\.md$|RELEASE\.md$|app\/(?:core-manifest\.json|production-files\.json|runtime\/modules\.php)$)/.test(path);
    if (changes.status!=='ahead' || changes.behind_by!==0 || changes.ahead_by!==1 || changes.merge_base_commit?.sha!==base
        || !Array.isArray(changes.files) || !changes.files.length || changes.files.length>=300
        || changes.files.some(file=>!allowed(file.filename) || file.previous_filename && !allowed(file.previous_filename))) {
        throw new Error('BLOCKED CI installation must contain only CI, fixtures, maintainer docs and generated companions.');
    }
    requireOwnerRuleset(call,repository,'main','Reviewed release promotion to main','Release qualification');
    const jobs=maintenanceRows(call,`repos/${repository}/actions/runs/${env.GITHUB_RUN_ID}/attempts/${env.GITHUB_RUN_ATTEMPT}/jobs`,'jobs');
    const owners=[];
    for (const label of [...fullMatrixOwners(),'Authoritative release audit']) {
        const rows=jobs.filter(job=>job.name==='Full CI for the published prepared SHA / '+label);
        if (rows.length!==1 || rows[0].run_id!==Number(env.GITHUB_RUN_ID) || rows[0].run_attempt!==Number(env.GITHUB_RUN_ATTEMPT)
            || rows[0].status!=='completed' || rows[0].conclusion!=='success') throw new Error('BLOCKED incomplete CI installation owner: '+label);
        owners.push({id:rows[0].id,name:rows[0].name,status:'completed',conclusion:'success'});
    }
    const commit=call(`repos/${repository}/commits/${candidate}`);
    if (commit?.sha!==candidate || commit.parents?.length!==1 || commit.parents[0].sha!==base
        || !/^[a-f0-9]{40}$/.test(commit.commit?.tree?.sha ?? '')) throw new Error('BLOCKED CI installation requires the final single-commit tree above main.');
    return {schema_version:1,repository,branch:env.GITHUB_REF_NAME,candidate_sha:candidate,candidate_tree:commit.commit.tree.sha,
        comparison_base:base,workflow_sha:env.GITHUB_SHA,run_id:env.GITHUB_RUN_ID,run_attempt:Number(env.GITHUB_RUN_ATTEMPT),native_owners:owners};
}

/** Open or reuse the bot-authored PR and request ordinary protected auto-merge after human approval.
 * @param {CiInstallation} record Original retained successful candidate identity.
 * @param {Record<string,string|undefined>} env Actual owner-selected workflow environment.
 * @param {function(string,string=,(Record<string,unknown>|null)=):Record<string,unknown>} call Independent provider reader/writer.
 * @returns {{number:number,html_url:string}} Exact reviewable main PR; checks and review remain enforced by GitHub.
 */
export function proposeCiInstallation(record,env,call) {
    const fresh=qualifyCiInstallation(env,call);
    if (JSON.stringify(fresh)!==JSON.stringify(record)) throw new Error('BLOCKED CI qualification changed after retention.');
    const {repository,candidate_sha:candidate,comparison_base:base}=record;
    call(`repos/${repository}/check-runs`,'POST',{name:'Release qualification',head_sha:candidate,status:'completed',conclusion:'success',
        external_id:`maintenance:${record.run_id}:${record.run_attempt}:${candidate}`,
        output:{title:'Complete CI installation qualification',summary:'All native FULL owners and authoritative release audit passed. Human main approval remains required.'}});
    const listed=call(`repos/${repository}/pulls?state=open&base=main&head=${encodeURIComponent(repository.split('/')[0]+':'+record.branch)}&per_page=100`);
    if (!Array.isArray(listed) || listed.length>1) throw new Error('BLOCKED ambiguous CI installation PR.');
    let pr=listed[0];
    if (pr) pr=call(`repos/${repository}/pulls/${pr.number}`);
    else pr=call(`repos/${repository}/pulls`,'POST',{title:'fix(ci): qualify selected feature workflows and trust develop for releases',head:record.branch,base:'main',
        body:`Implements #235. Run integration using the maintainer-selected feature workflow and its tests; retain complete FULL execution for publication recovery. Release assembly trusts protected develop and prepares notes, manuals, manifest and attachments without repeating source qualification.\n\nValidation: all required native FULL owners plus authoritative release audit, https://github.com/${repository}/actions/runs/${record.run_id}.\nCandidate: ${candidate}. Base: ${base}.\n\nHuman approval is required. No tag or production release is created by this installation.`});
    requireBotPullRequest(pr,repository,candidate,'main');
    if (pr.state!=='open' || pr.base?.sha!==base) throw new Error('BLOCKED CI installation PR base or state changed.');
    const merged=call('graphql','POST',{query:'mutation($id:ID!,$head:GitObjectID!){enablePullRequestAutoMerge(input:{pullRequestId:$id,mergeMethod:MERGE,expectedHeadOid:$head}){pullRequest{number}}}',
        variables:{id:pr.node_id,head:candidate}});
    if (merged.errors || merged.data?.enablePullRequestAutoMerge?.pullRequest?.number!==pr.number) throw new Error('BLOCKED protected CI installation auto-merge was not enabled.');
    return {number:pr.number,html_url:pr.html_url};
}

if (process.argv[1] && import.meta.url===pathToFileURL(resolve(process.argv[1])).href) {
    try {
        const e=process.env;
        if (process.argv[2]==='qualify') writeFileSync(e.RUNNER_TEMP+'/ci-installation.json',JSON.stringify(qualifyCiInstallation(e,api),null,2)+'\n');
        else if (process.argv[2]==='propose') {
            const record=JSON.parse(readFileSync(e.RUNNER_TEMP+'/ci-installation.json','utf8'));
            const pr=proposeCiInstallation(record,e,api);
            appendFileSync(e.GITHUB_STEP_SUMMARY,`CI installation PR: ${pr.html_url}\n`);
        } else throw new Error('BLOCKED unknown CI installation operation.');
    } catch (error) {process.stderr.write(error.message+'\n');process.exitCode=1;}
}
