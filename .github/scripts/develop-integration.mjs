/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/develop-integration.mjs
 * Module Type: Protected Develop Integration Evidence Publisher
 * Purpose: Verify source, artifact and full-matrix evidence before a protected non-forced develop fast-forward.
 * Responsibilities:
 *   - Bind BASIC to the original single-commit source S and FULL to prepared candidate A
 *   - Publish allowlisted generated data through GitHub APIs without checking out candidate code
 *   - Record actual native owners and create same-run exact-candidate required check runs
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {readCoverageArtifact} from './coverage-artifacts.mjs';

/** Identify the installed main-owned integration workflow that may publish protected refs.
 * Type: string. Units: repository-relative workflow path. Scope: one integration run.
 * Consumers: workflow-context validation and retained integration records.
 * Rationale: one explicit path prevents another workflow from presenting the privileged owner identity.
 */
const WORKFLOW_PATH='.github/workflows/develop-integration.yml';
/** Identify the source workflow that produces the original squash's BASIC evidence.
 * Type: string. Units: repository-relative workflow path. Scope: selected source S.
 * Consumers: BASIC run selection, evidence verification and inherited authority validation.
 * Rationale: a fixed producer path excludes unrelated successful checks from BASIC qualification.
 */
const PREPARATION_PATH='.github/workflows/candidate-preparation.yml';
/** Pin every explicit executable BASIC evaluator input to protected develop's inherited content.
 * Type: string[]. Units: repository-relative file paths. Scope: source S compared with develop P.
 * Consumers: requireInheritedBasicWorkflow and its complete source-contract subtree comparison.
 * Rationale: branch-authored evaluator changes must not authenticate their own BASIC result.
 */
const BASIC_AUTHORITY_PATHS=[
    PREPARATION_PATH,
    'scripts/audit.php','scripts/cli_guard.php','scripts/audit_lib.php','scripts/audit_process.php',
    'scripts/audit_performance.php','scripts/audit_source_evidence.php','scripts/audit_route_performance.php',
    'scripts/audit_winapp.php','scripts/runtime_plan_metrics.php','scripts/audit_registry.php','scripts/audit_php_registry.php',
    'scripts/audit_performance_registry.php','scripts/audit_route_probe_registry.php',
    'scripts/source_contract_debt_baseline.json','scripts/check_source_documentation.php',
    'scripts/source_contracts/debt_ratchet.php','scripts/source_contracts/inventory.php',
    'scripts/source_contracts/php.php','scripts/source_contracts/javascript.php',
    'scripts/source_contracts/python.php','scripts/source_contracts/scripts.php',
    'scripts/source_contracts/changes.php','scripts/source_contracts/python_scan.py',
];
/** Map the three generated artifact entry names to the only permitted candidate-owned paths.
 * Type: Map<string,string>. Units: artifact entry names and repository-relative file paths.
 * Scope: read-only preparation transport and generated-only candidate publication.
 * Consumers: publishPreparedCandidate and exact generated-delta verification.
 * Rationale: the publisher accepts generated data only and cannot install arbitrary source or workflow files.
 */
const GENERATED=new Map([
    ['modules.php','app/runtime/modules.php'],
    ['production-files.json','app/production-files.json'],
    ['core-manifest.json','app/core-manifest.json'],
]);
/** Record the maintainer-requested minimal source gate without functional regression owners.
 * Type: string[]. Units: central audit suite identifiers. Scope: original squash S.
 * Consumers: integration records and downstream release coverage verification.
 * Rationale: syntax and named-declaration checks are the complete approved BASIC contract.
 */
const BASIC_PROFILE=['php-lint','js-lint','source-documentation-changed'];
/** Require every native owner of the existing complete integration matrix.
 * Type: string[]. Units: GitHub Actions job names. Scope: exact prepared integration candidate A.
 * Consumers: validateDevelopIntegration and its recorded successful native owner IDs.
 * Rationale: FULL must retain source, database, browser, package and aggregate owners before develop moves.
 */
const FULL_OWNERS=[
    'Read-only generated-state and source preflight',
    'Positive production package (ubuntu-24.04)',
    'Positive production package (windows-2025)',
    'Positive production package (macos-latest)',
    'PHP 8.3 workflows (mysql:8.4, Unicode)',
    'PHP 8.3 workflows (mariadb:10.11, Unicode)',
    'PHP 8.5 workflows (mariadb:11.4, Unicode)',
    'PHP 8.1 source (unicode)',
    'PHP 8.5 source (ascii)',
    'Required Chromium fixtures',
    'Complete required CI matrix',
];

/** Encode one branch ref while preserving its structural slash separators.
 * @param {string} branch Valid complete Git branch name.
 * @returns {string} URL-safe branch ref path.
 */
function encodedBranch(branch) {
    return encodeURIComponent(branch).replace(/%2F/gi,'/');
}

/** Match the installed workflow reference including GitHub's repository-prefixed environment form.
 * @param {string} repository Exact owner/repository configured for this run.
 * @param {string} workflowRef GitHub workflow reference environment value.
 * @returns {boolean} True only for this workflow loaded from the main branch.
 */
function trustedWorkflowRef(repository,workflowRef) {
    return workflowRef===WORKFLOW_PATH+'@refs/heads/main'
        || workflowRef===repository+'/'+WORKFLOW_PATH+'@refs/heads/main';
}

/** GitHub API adapter supporting exact paths, methods and JSON payloads.
 * @typedef {function(string,string=,(Record<string,unknown>|null)=):Record<string,unknown>} GithubApi
 */
/** Integration request bound to the user-selected feature source and observed develop lease.
 * @typedef {{branch:string,sourceSha:string,candidateSha:string,expectedDevelopSha:string,sourceBase:string,basicRunId:number|string,basicRunAttempt:number|string,integrationCheckId?:number,matrixCheckId?:number}} IntegrationRequest
 */
/** Protected workflow identity taken from the current GitHub Actions environment.
 * @typedef {{repository:string,workflowPath:string,workflowRef:string,eventName:string,ref:string,runId:number|string,runAttempt:number|string,headSha:string}} WorkflowContext
 */

/** Validate a Git branch name while allowing ordinary punctuation such as comma and hash.
 * @param {string} branch Exact source branch name.
 * @returns {boolean} True when the name is a valid single Git ref component sequence.
 */
export function validBranchName(branch) {
    return typeof branch==='string' && branch.length>0 && branch.length<=240
        && !branch.startsWith('-') && !branch.startsWith('/') && !branch.endsWith('/')
        && !branch.endsWith('.') && !branch.includes('..') && !branch.includes('@{')
        && !/[\x00-\x20\x7f~^:?*\[\\]/.test(branch)
        && branch.split('/').every(part=>part.length>0 && !part.startsWith('.') && !part.endsWith('.lock'));
}

/** Require an exact selected branch SHA and current observed develop SHA.
 * @param {GithubApi} call Authenticated GitHub API adapter.
 * @param {string} repository Owner/repository.
 * @param {string} branch Valid exact source branch.
 * @param {string} expectedCandidate Expected currently published candidate SHA A.
 * @param {string} expectedDevelop Expected develop lease SHA P.
 * @returns {{source:string,develop:string}} Current exact branch heads.
 */
function readHeads(call,repository,branch,expectedCandidate,expectedDevelop) {
    const source=call('repos/'+repository+'/git/ref/heads/'+encodedBranch(branch))?.object?.sha;
    const develop=call('repos/'+repository+'/git/ref/heads/develop')?.object?.sha;
    if (source!==expectedCandidate || develop!==expectedDevelop) throw new Error('BLOCKED prepared source or develop ref changed.');
    return {source,develop};
}

/** Read the complete recursive Git tree needed to compare every path without the 300-file commit API limit.
 * @param {GithubApi} call Authenticated GitHub API adapter.
 * @param {string} repository Owner/repository.
 * @param {string} treeSha Exact tree object SHA.
 * @returns {Map<string,string>} Complete leaf-path-to-mode/type/object identity mapping; directory rows are validated but excluded from delta comparison.
 */
function completeTree(call,repository,treeSha) {
    const response=call('repos/'+repository+'/git/trees/'+treeSha+'?recursive=1');
    if (response?.sha!==treeSha || response?.truncated!==false || !Array.isArray(response.tree)) {
        throw new Error('BLOCKED complete recursive Git tree is unavailable or truncated.');
    }
    const entries=new Map(),files=new Map();
    for (const entry of response.tree) {
        const validModeType=(entry?.mode==='040000' && entry?.type==='tree')
            || (['100644','100755','120000'].includes(entry?.mode) && entry?.type==='blob')
            || (entry?.mode==='160000' && entry?.type==='commit');
        if (typeof entry?.path!=='string' || entry.path.length===0 || !/^[a-f0-9]{40}$/.test(entry?.sha ?? '')
            || !validModeType || entries.has(entry.path)) throw new Error('BLOCKED malformed or duplicate Git tree path.');
        entries.set(entry.path,entry);
        // Tree-object SHAs change when a leaf below the directory changes; only leaf identity
        // describes the source delta relevant to allowlisted generated files and BASIC tools.
        if (entry.type!=='tree') files.set(entry.path,entry.mode+':'+entry.type+':'+entry.sha);
    }
    return files;
}

/** Require the source checkout's BASIC workflow and transitive evaluator inputs to match protected develop.
 * @param {GithubApi} call Authenticated GitHub API adapter.
 * @param {string} repository Owner/repository.
 * @param {string} sourceTree Tree SHA for source S.
 * @param {string} baseTree Tree SHA for protected develop P.
 * @returns {void} Returns only when all pinned BASIC evaluator files and source-contract leaves match both trees.
 */
function requireInheritedBasicAuthority(call,repository,sourceTree,baseTree) {
    const source=completeTree(call,repository,sourceTree),base=completeTree(call,repository,baseTree);
    for (const path of BASIC_AUTHORITY_PATHS) {
        if (!source.has(path) || source.get(path)!==base.get(path)) {
            throw new Error('BLOCKED selected S changes or omits BASIC authority input inherited from protected develop P: '+path);
        }
    }
    const contractPrefix='scripts/source_contracts/';
    const contractPaths=new Set([...source.keys(),...base.keys()].filter(path=>path.startsWith(contractPrefix)));
    for (const path of contractPaths) {
        if (source.get(path)!==base.get(path)) {
            throw new Error('BLOCKED selected S changes BASIC source-contract executable or configuration input inherited from P: '+path);
        }
    }
}

/** Load a bounded complete REST collection while rejecting duplicate IDs and changing totals.
 * @param {GithubApi} call Authenticated GitHub API adapter.
 * @param {string} path Collection endpoint with no page parameter.
 * @param {string} key Response property containing rows.
 * @returns {Record<string,unknown>[]} Complete stable rows.
 */
function completeRows(call,path,key) {
    const rows=[],ids=new Set();let total=null;
    for (let page=1;page<=20;page++) {
        const separator=path.includes('?')?'&':'?';
        const response=call(path+separator+'per_page=100&page='+page);
        if (!Array.isArray(response?.[key]) || !Number.isSafeInteger(response.total_count) || response.total_count<0
            || (total!==null && total!==response.total_count)) throw new Error('BLOCKED complete '+key+' inventory unavailable.');
        total=response.total_count;
        for (const row of response[key]) {
            if (!Number.isSafeInteger(row?.id) || ids.has(row.id)) throw new Error('BLOCKED duplicate or unidentified '+key+' row.');
            ids.add(row.id);rows.push(row);
        }
        if (rows.length===total) return rows;
        if (response[key].length===0 || rows.length>total) throw new Error('BLOCKED truncated or inconsistent '+key+' inventory.');
    }
    throw new Error('BLOCKED '+key+' inventory exceeds the bounded page limit.');
}

/** Prove the selected source is exactly one commit above develop and candidate is generated-only.
 * @param {GithubApi} call Authenticated GitHub API adapter.
 * @param {string} repository Owner/repository.
 * @param {string} sourceSha Original feature source S.
 * @param {string} candidateSha Prepared candidate A.
 * @param {string} developSha Observed develop P.
 * @returns {{tree:string}} Candidate tree SHA.
 */
function verifyAncestry(call,repository,sourceSha,candidateSha,developSha) {
    const source=call('repos/'+repository+'/commits/'+sourceSha);
    if (source?.sha!==sourceSha || source.commit?.tree?.sha===undefined
        || source.parents?.length!==1 || source.parents[0]?.sha!==developSha) {
        throw new Error('BLOCKED source S must have develop P as its sole parent.');
    }
    const base=call('repos/'+repository+'/commits/'+developSha);
    if (base?.sha!==developSha || typeof base.commit?.tree?.sha!=='string') throw new Error('BLOCKED develop P tree is unavailable.');
    requireInheritedBasicAuthority(call,repository,source.commit.tree.sha,base.commit.tree.sha);
    if (candidateSha!==sourceSha) {
        const candidate=call('repos/'+repository+'/commits/'+candidateSha);
        if (candidate?.sha!==candidateSha || candidate.parents?.length!==1 || candidate.parents[0]?.sha!==sourceSha) {
            throw new Error('BLOCKED prepared A must be S or one generated-only child of S.');
        }
        const before=completeTree(call,repository,source.commit.tree.sha),after=completeTree(call,repository,candidate.commit.tree.sha);
        const changed=[];
        for (const path of new Set([...before.keys(),...after.keys()])) if (before.get(path)!==after.get(path)) changed.push(path);
        if (changed.length<1 || changed.length>3 || changed.some(path=>!['app/runtime/modules.php',
            'app/production-files.json','app/core-manifest.json'].includes(path))) {
            throw new Error('BLOCKED generated candidate diff contains missing or non-allowlisted paths.');
        }
    }
    const candidate=call('repos/'+repository+'/commits/'+candidateSha);
    if (candidate?.sha!==candidateSha || typeof candidate.commit?.tree?.sha!=='string') {
        throw new Error('BLOCKED candidate commit tree is unavailable.');
    }
    return {tree:candidate.commit.tree.sha};
}

/** Resolve exact native check runs without accepting duplicate or hidden red owners.
 * @param {GithubApi} call Authenticated GitHub API adapter.
 * @param {string} repository Owner/repository.
 * @param {string} sha Exact commit whose checks are required.
 * @param {string} prefix Required current-workflow job prefix.
 * @param {string} externalId Exact check-run external ID.
 * @returns {Record<string,unknown>} Exact unique successful check run.
 */
function exactCheck(call,repository,sha,prefix,externalId) {
    const checks=completeRows(call,'repos/'+repository+'/commits/'+sha+'/check-runs?filter=all','check_runs');
    const matches=checks.filter(check=>check.name===prefix && check.external_id===externalId);
    if (matches.length!==1) throw new Error('BLOCKED exact required check is missing or duplicated.');
    const check=matches[0];
    if (check.head_sha!==sha || check.status!=='completed' || check.conclusion!=='success'
        || check.app?.id!==15368) throw new Error('BLOCKED required check is not a successful GitHub Actions check on the exact SHA.');
    return check;
}

/** Validate the latest exact-S BASIC run, its native suite and the separately grouped custom check.
 * @param {GithubApi} call Authenticated GitHub API adapter.
 * @param {string} repository Owner/repository.
 * @param {string} branch Exact selected source branch.
 * @param {string} sourceSha Exact source S.
 * @param {string|number} runId Selected candidate-preparation workflow run ID.
 * @param {string|number} attempt Selected latest workflow attempt.
 * @returns {Record<string,unknown>} Verified BASIC check run.
 */
function validateBasicEvidence(call,repository,branch,sourceSha,runId,attempt) {
    const run=call('repos/'+repository+'/actions/runs/'+String(runId));
    if (String(run?.id)!==String(runId) || run?.path!==PREPARATION_PATH || run?.head_branch!==branch
        || run?.head_sha!==sourceSha || !['push','workflow_dispatch'].includes(run?.event)
        || run?.status!=='completed' || run?.conclusion!=='success' || String(run?.run_attempt)!==String(attempt)) {
        throw new Error('BLOCKED BASIC run is not the latest completed successful candidate-preparation attempt for S.');
    }
    const basic=exactCheck(call,repository,sourceSha,'Basic source qualification',
        'basic:'+String(runId)+':'+String(attempt)+':'+sourceSha);
    const suite=call('repos/'+repository+'/check-suites/'+basic.check_suite?.id);
    if (!Number.isSafeInteger(run.check_suite_id) || run.check_suite_id<1
        || suite?.id!==basic.check_suite?.id) {
        throw new Error('BLOCKED BASIC native or custom check suite identity is unavailable.');
    }
    // GitHub may group REST-created checks into an earlier suite for the same commit/App.
    // The actual run/attempt and native job remain the execution authority, independently of that grouping.
    const nativeSuite=run.check_suite_id===suite.id ? suite
        : call('repos/'+repository+'/check-suites/'+run.check_suite_id);
    if (nativeSuite?.id!==run.check_suite_id || [suite,nativeSuite].some(item=>item?.head_branch!==branch
        || item?.head_sha!==sourceSha || item?.status!=='completed'
        || item?.conclusion!=='success' || item?.app?.id!==15368)) {
        throw new Error('BLOCKED BASIC native or custom check suite differs from the selected branch and source SHA.');
    }
    const jobs=completeRows(call,'repos/'+repository+'/actions/runs/'+String(runId)
        +'/attempts/'+String(attempt)+'/jobs','jobs');
    const owners=jobs.filter(job=>job.name==='Basic source checks' && job.run_id===Number(runId)
        && job.run_attempt===Number(attempt) && job.workflow_name==='Basic source checks');
    if (owners.length!==1 || owners[0].status!=='completed' || owners[0].conclusion!=='success') {
        throw new Error('BLOCKED native BASIC source job is missing, duplicated or unsuccessful.');
    }
    return basic;
}

/** Resolve the selected branch head, its direct develop parent and newest successful BASIC attempt.
 * @param {string} branch User-selected feature branch name.
 * @param {WorkflowContext} context Current main-owned workflow identity.
 * @param {string} repository Configured owner/repository.
 * @param {GithubApi} call Authenticated GitHub API adapter.
 * @returns {{branch:string,sourceSha:string,expectedDevelopSha:string,sourceBase:string,basicRunId:string,basicRunAttempt:string}} Exact selected source and evidence inputs for preparation.
 */
export function selectDevelopIntegration(branch,context,repository,call) {
    if (!validBranchName(branch) || !branch.startsWith('feature/') || context?.repository!==repository
        || context.workflowPath!==WORKFLOW_PATH || !trustedWorkflowRef(repository,context.workflowRef)
        || context.eventName!=='workflow_dispatch' || context.ref!=='refs/heads/main'
        || !/^\d+$/.test(String(context.runId)) || !/^\d+$/.test(String(context.runAttempt))
        || !/^[a-f0-9]{40}$/.test(context.headSha)) throw new Error('BLOCKED source selection is outside the main-owned workflow context.');
    const active=call('repos/'+repository+'/actions/runs/'+String(context.runId));
    if (String(active?.id)!==String(context.runId) || active?.path!==WORKFLOW_PATH || active?.head_branch!=='main'
        || active?.head_sha!==context.headSha || active?.event!=='workflow_dispatch' || active?.status!=='in_progress'
        || String(active?.run_attempt)!==String(context.runAttempt)) throw new Error('BLOCKED source selection workflow run is not current and active.');
    const sourceSha=call('repos/'+repository+'/git/ref/heads/'+encodedBranch(branch))?.object?.sha;
    const expectedDevelopSha=call('repos/'+repository+'/git/ref/heads/develop')?.object?.sha;
    if (!/^[a-f0-9]{40}$/.test(sourceSha ?? '') || !/^[a-f0-9]{40}$/.test(expectedDevelopSha ?? '')) {
        throw new Error('BLOCKED selected source or develop ref is unavailable.');
    }
    const source=call('repos/'+repository+'/commits/'+sourceSha);
    if (source?.sha!==sourceSha || source.parents?.length!==1 || source.parents[0]?.sha!==expectedDevelopSha) {
        throw new Error('BLOCKED selected source S must be exactly one commit above current develop P.');
    }
    const base=call('repos/'+repository+'/commits/'+expectedDevelopSha);
    if (base?.sha!==expectedDevelopSha || typeof base.commit?.tree?.sha!=='string') throw new Error('BLOCKED current develop P tree is unavailable.');
    requireInheritedBasicAuthority(call,repository,source.commit.tree.sha,base.commit.tree.sha);
    const runs=completeRows(call,'repos/'+repository+'/actions/workflows/candidate-preparation.yml/runs?branch='
        +encodeURIComponent(branch)+'&head_sha='+sourceSha,'workflow_runs');
    const exact=runs.filter(run=>run.head_branch===branch && run.head_sha===sourceSha
        && ['push','workflow_dispatch'].includes(run.event)).sort((left,right)=>Number(right.id)-Number(left.id));
    const latest=exact[0];
    if (!latest || !Number.isSafeInteger(latest.run_attempt) || latest.status!=='completed' || latest.conclusion!=='success') {
        throw new Error('BLOCKED selected source has no latest completed successful BASIC workflow run.');
    }
    validateBasicEvidence(call,repository,branch,sourceSha,latest.id,latest.run_attempt);
    return {branch,sourceSha,expectedDevelopSha,sourceBase:expectedDevelopSha,
        basicRunId:String(latest.id),basicRunAttempt:String(latest.run_attempt)};
}

/** Check exact protected-workflow execution context and all eleven actual matrix owners.
 * @param {IntegrationRequest} request Selected source and candidate identity.
 * @param {WorkflowContext} context Current GitHub Actions identity.
 * @param {string} repository Configured repository identity.
 * @param {GithubApi} call Authenticated GitHub API adapter.
 * @returns {{plan:{branch:string,oldSha:string,newSha:string},record:Record<string,unknown>}} Validated plan and immutable evidence record.
 */
export function validateDevelopIntegration(request,context,repository,call) {
    if (!request || !validBranchName(request.branch) || !/^feature\//.test(request.branch)
        || ![request.sourceSha,request.candidateSha,request.expectedDevelopSha,request.sourceBase].every(value=>/^[a-f0-9]{40}$/.test(value))
        || request.sourceBase!==request.expectedDevelopSha || context?.repository!==repository
        || !/^\d+$/.test(String(request.basicRunId)) || !/^\d+$/.test(String(request.basicRunAttempt))
        || context.workflowPath!==WORKFLOW_PATH || !trustedWorkflowRef(repository,context.workflowRef)
        || context.eventName!=='workflow_dispatch' || context.ref!=='refs/heads/main'
        || !/^[a-f0-9]{40}$/.test(context.headSha) || !/^\d+$/.test(String(context.runId))
        || !/^\d+$/.test(String(context.runAttempt))) throw new Error('BLOCKED untrusted integration workflow identity or request.');
    const runId=String(context.runId),attempt=String(context.runAttempt);
    const currentRun=call('repos/'+repository+'/actions/runs/'+runId);
    if (String(currentRun?.id)!==runId || currentRun?.path!==WORKFLOW_PATH || currentRun?.event!=='workflow_dispatch'
        || currentRun?.head_branch!=='main' || currentRun?.head_sha!==context.headSha
        || currentRun?.status!=='in_progress' || String(currentRun?.run_attempt)!==attempt) {
        throw new Error('BLOCKED current publisher is not the exact active main-owned workflow attempt.');
    }
    const heads=readHeads(call,repository,request.branch,request.candidateSha,request.expectedDevelopSha);
    const ancestry=verifyAncestry(call,repository,request.sourceSha,request.candidateSha,request.expectedDevelopSha);
    const basicRuns=completeRows(call,'repos/'+repository+'/actions/workflows/candidate-preparation.yml/runs?branch='
        +encodeURIComponent(request.branch)+'&head_sha='+request.sourceSha,'workflow_runs');
    const matchingBasicRuns=basicRuns.filter(run=>run.head_branch===request.branch && run.head_sha===request.sourceSha
        && ['push','workflow_dispatch'].includes(run.event));
    const newestBasicRun=matchingBasicRuns.sort((left,right)=>Number(right.id)-Number(left.id))[0];
    if (!newestBasicRun || String(newestBasicRun.id)!==String(request.basicRunId)) {
        throw new Error('BLOCKED BASIC evidence is not from the newest retained run for this branch and S.');
    }
    validateBasicEvidence(call,repository,request.branch,request.sourceSha,request.basicRunId,request.basicRunAttempt);
    const jobs=completeRows(call,'repos/'+repository+'/actions/runs/'+runId+'/attempts/'+attempt+'/jobs','jobs');
    const owners=[];
    const auxiliaryNames=['Select squashed source','Prepare integration artifacts','Publish prepared integration candidate'];
    for (const name of FULL_OWNERS) {
        const matching=jobs.filter(job=>job.name==='Develop integration / '+name
            && job.run_id===Number(runId) && job.run_attempt===Number(attempt)
            && job.workflow_name==='Integrate into develop');
        if (matching.length!==1 || matching[0].status!=='completed' || matching[0].conclusion!=='success') {
            throw new Error('BLOCKED missing, duplicate or unsuccessful native full-matrix owner: '+name);
        }
        owners.push({id:matching[0].id,name:matching[0].name,status:matching[0].status,conclusion:matching[0].conclusion});
    }
    for (const name of auxiliaryNames) {
        const matches=jobs.filter(job=>job.name===name && job.run_id===Number(runId)
            && job.run_attempt===Number(attempt) && job.workflow_name==='Integrate into develop');
        if (matches.length!==1 || matches[0].status!=='completed' || matches[0].conclusion!=='success') {
            throw new Error('BLOCKED integration workflow auxiliary job is missing, duplicated or unsuccessful: '+name);
        }
    }
    const publisher=jobs.filter(job=>job.name==='Publish develop integration'
        && job.run_id===Number(runId) && job.run_attempt===Number(attempt)
        && job.workflow_name==='Integrate into develop');
    if (publisher.length!==1 || !['queued','in_progress'].includes(publisher[0].status)) {
        throw new Error('BLOCKED publisher must be the sole active job in the current integration run.');
    }
    const allowed=new Set([...FULL_OWNERS.map(name=>'Develop integration / '+name),...auxiliaryNames,
        'Publish develop integration','Develop integration / Authoritative release audit']);
    if (jobs.some(job=>!allowed.has(job.name) || job.run_id!==Number(runId)
        || job.run_attempt!==Number(attempt) || job.workflow_name!=='Integrate into develop')
        || jobs.filter(job=>job.name==='Develop integration / Authoritative release audit').some(job=>
            job.status!=='completed' || job.conclusion!=='skipped')) {
        throw new Error('BLOCKED unexpected integration job or release-only job outcome.');
    }
    const externalId='integration:'+runId+':'+attempt+':'+request.candidateSha;
    if (request.integrationCheckId!==undefined) exactCheck(call,repository,request.candidateSha,
        'Develop integration qualification',externalId);
    if (request.matrixCheckId!==undefined) exactCheck(call,repository,request.candidateSha,'Complete required CI matrix',externalId);
    const record={schema_version:1,repository,source_branch:request.branch,source_sha:request.sourceSha,
        candidate_sha:request.candidateSha,candidate_tree:ancestry.tree,expected_develop_sha:request.expectedDevelopSha,
        source_base:request.sourceBase,workflow_sha:context.headSha,workflow_path:WORKFLOW_PATH,
        run_id:runId,run_attempt:attempt,basic_profile:BASIC_PROFILE,native_owners:owners};
    return {plan:{branch:'develop',oldSha:heads.develop,newSha:request.candidateSha},record};
}

/** Create exact-candidate required checks, reread the develop lease, and attempt one normal fast-forward.
 * @param {IntegrationRequest} request Selected source and candidate identity.
 * @param {WorkflowContext} context Current protected workflow identity.
 * @param {string} repository Configured repository.
 * @param {GithubApi} call Authenticated GitHub API adapter.
 * @returns {{branch:string,sha:string,record:Record<string,unknown>}} Completed synchronization and attached integration proof.
 */
export function integrateDevelop(request,context,repository,call) {
    const checked=validateDevelopIntegration(request,context,repository,call);
    const runId=String(context.runId),attempt=String(context.runAttempt),externalId='integration:'+runId+':'+attempt+':'+request.candidateSha;
    const makeCheck=name=>call('repos/'+repository+'/check-runs','POST',{name,head_sha:request.candidateSha,
        external_id:externalId,status:'completed',conclusion:'success',output:{title:name,summary:'Exact source, ancestry and mandatory native owner evidence verified.'}});
    const integration=makeCheck('Develop integration qualification');
    const matrix=makeCheck('Complete required CI matrix');
    if (!Number.isSafeInteger(integration?.id) || !Number.isSafeInteger(matrix?.id)) throw new Error('BLOCKED required check creation was not confirmed.');
    const fresh=readHeads(call,repository,request.branch,request.candidateSha,request.expectedDevelopSha);
    if (fresh.develop!==checked.plan.oldSha) throw new Error('BLOCKED develop changed before fast-forward write.');
    const updated=call('repos/'+repository+'/git/refs/heads/develop','PATCH',{sha:request.candidateSha,force:false});
    if (updated?.object?.sha!==request.candidateSha) throw new Error('BLOCKED GitHub did not confirm the non-forced develop fast-forward.');
    const after=call('repos/'+repository+'/git/ref/heads/develop');
    if (after?.object?.sha!==request.candidateSha) throw new Error('BLOCKED develop moved after fast-forward publication.');
    const record={...checked.record,check_run_id:integration.id,matrix_check_run_id:matrix.id};
    return {branch:'develop',sha:request.candidateSha,record};
}

/** Validate a preparation artifact and create its generated-only child commit through GitHub Data APIs.
 * @param {{repository:string,branch:string,sourceSha:string,expectedDevelopSha:string,artifactId:number,artifactDigest:string,runId:number|string}} request Exact selected S/P and authenticated artifact metadata.
 * @param {WorkflowContext} context Main-owned publisher identity.
 * @param {GithubApi} call Authenticated synchronous GitHub API adapter.
 * @param {{api?:function(string):Record<string,unknown>,download?:function(string,number):Buffer}} adapters Optional independent provider and archive transports for inert fixtures.
 * @returns {{candidateSha:string,candidateTree:string,artifactId:number,artifactDigest:string}} Published candidate identity.
 */
export function publishPreparedCandidate(request,context,call,adapters={}) {
    if (context?.repository!==request?.repository || context.workflowPath!==WORKFLOW_PATH || !trustedWorkflowRef(request.repository,context.workflowRef)
        || context.eventName!=='workflow_dispatch' || context.ref!=='refs/heads/main'
        || String(request?.runId)!==String(context.runId) || !Number.isSafeInteger(request.artifactId)
        || !/^sha256:[a-f0-9]{64}$/.test(request.artifactDigest) || !validBranchName(request.branch)
        || !/^feature\//.test(request.branch) || !/^[a-f0-9]{40}$/.test(request.sourceSha)
        || !/^[a-f0-9]{40}$/.test(request.expectedDevelopSha)) throw new Error('BLOCKED artifact publisher is outside the trusted main workflow context.');
    const {repository,branch,sourceSha}=request;
    const ownRun=call('repos/'+repository+'/actions/runs/'+String(context.runId));
    if (String(ownRun?.id)!==String(context.runId) || ownRun?.path!==WORKFLOW_PATH || ownRun?.head_branch!=='main'
        || ownRun?.head_sha!==context.headSha || ownRun?.event!=='workflow_dispatch' || ownRun?.status!=='in_progress'
        || String(ownRun?.run_attempt)!==String(context.runAttempt)) {
        throw new Error('BLOCKED candidate publisher is not this active main-owned workflow run.');
    }
    const artifactAdapters={api:adapters.api ?? (path=>call(path))};
    if (adapters.download) artifactAdapters.download=adapters.download;
    const transport=readCoverageArtifact(repository,request.artifactId,String(request.runId),request.artifactDigest,
        'develop-integration-prepared-state',[...GENERATED.keys()],artifactAdapters);
    const entries=transport.entries;
    const ref=call('repos/'+repository+'/git/ref/heads/'+encodedBranch(branch));
    if (ref?.object?.sha!==sourceSha) throw new Error('BLOCKED selected source branch changed before generated publication.');
    const observedDevelop=call('repos/'+repository+'/git/ref/heads/develop');
    if (observedDevelop?.object?.sha!==request.expectedDevelopSha) throw new Error('BLOCKED develop changed before generated publication.');
    const source=call('repos/'+repository+'/commits/'+sourceSha);
    if (source?.sha!==sourceSha || source.parents?.length!==1 || source.parents[0]?.sha!==request.expectedDevelopSha) {
        throw new Error('BLOCKED source S is not exactly one commit above expected develop P.');
    }
    const baseTree=source.commit?.tree?.sha;
    if (typeof baseTree!=='string') throw new Error('BLOCKED source tree unavailable.');
    const additions=[];
    for (const [archiveName,path] of GENERATED) {
        const bytes=entries.get(archiveName);
        if (!bytes) throw new Error('BLOCKED generated artifact omitted '+archiveName+'.');
        const blob=call('repos/'+repository+'/git/blobs','POST',{content:bytes.toString('base64'),encoding:'base64'});
        if (!/^[a-f0-9]{40}$/.test(blob?.sha ?? '')) throw new Error('BLOCKED generated data blob creation failed.');
        additions.push({path,mode:'100644',type:'blob',sha:blob.sha});
    }
    const tree=call('repos/'+repository+'/git/trees','POST',{base_tree:baseTree,tree:additions});
    if (!/^[a-f0-9]{40}$/.test(tree?.sha ?? '')) throw new Error('BLOCKED generated candidate tree creation failed.');
    if (tree.sha===baseTree) return {candidateSha:sourceSha,candidateTree:baseTree,
        artifactId:request.artifactId,artifactDigest:request.artifactDigest};
    const commit=call('repos/'+repository+'/git/commits','POST',{message:'Prepare generated state for develop integration',tree:tree.sha,parents:[sourceSha]});
    if (!/^[a-f0-9]{40}$/.test(commit?.sha ?? '')) throw new Error('BLOCKED generated candidate commit creation failed.');
    const latest=call('repos/'+repository+'/git/ref/heads/'+encodedBranch(branch));
    if (latest?.object?.sha!==sourceSha) throw new Error('BLOCKED source branch moved before generated candidate publication.');
    const latestDevelop=call('repos/'+repository+'/git/ref/heads/develop');
    if (latestDevelop?.object?.sha!==request.expectedDevelopSha) throw new Error('BLOCKED develop moved before generated candidate publication.');
    const written=call('repos/'+repository+'/git/refs/heads/'+encodedBranch(branch),'PATCH',{sha:commit.sha,force:false});
    if (written?.object?.sha!==commit.sha) throw new Error('BLOCKED generated candidate branch update failed.');
    const verified=call('repos/'+repository+'/git/ref/heads/'+encodedBranch(branch));
    if (verified?.object?.sha!==commit.sha) throw new Error('BLOCKED generated candidate branch changed after publication.');
    return {candidateSha:commit.sha,candidateTree:tree.sha,artifactId:request.artifactId,artifactDigest:request.artifactDigest};
}
