/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/develop-integration.mjs
 * Module Type: Protected Develop Integration Evidence Publisher
 * Purpose: Verify source, artifact and full-matrix evidence before a protected non-forced develop fast-forward.
 * Responsibilities:
 *   - Bind fresh BASIC and FULL to the final single-commit candidate after automatic preparation
 *   - Publish allowlisted generated data through GitHub APIs without checking out candidate code
 *   - Record actual native owners and create same-run exact-candidate required check runs
 *   - Bind the protected-branch commit status to that same verified FULL and Actions identity
 *   - Dispatch and await BASIC for verified one-commit prepared candidates
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {readCoverageArtifact,loadIntegrationQualification} from './coverage-artifacts.mjs';
import {fullMatrixOwners,requireCurrentWorkflowOwners} from './ci-owner-contract.mjs';
import {requireOwnerRuleset} from './release-owner-authorization.mjs';
import {bindTrustedBasicReport} from './trusted-basic.mjs';
import {loadFeatureExecution,verifyFeatureOwners,verifyFeatureBasic} from './feature-qualification.mjs';

/**
 * Space status requests while awaiting the prepared candidate's BASIC run.
 * Type: number.
 * Units: milliseconds.
 * Scope: one prepared candidate's BASIC qualification.
 * Consumers: waitForPreparedBasic.
 * Rationale: five-second polling observes completion without a tight API loop.
 */
const PREPARED_BASIC_POLL_INTERVAL_MS=5000;

/**
 * Bound waiting for the prepared candidate's BASIC run, including queue time.
 * Type: number.
 * Units: milliseconds.
 * Scope: one prepared candidate's BASIC qualification.
 * Consumers: waitForPreparedBasic.
 * Rationale: fifteen minutes leaves time within the twenty-minute publisher job for setup and evidence validation.
 */
const PREPARED_BASIC_WAIT_TIMEOUT_MS=15*60*1000;

/** Bound retries for GitHub's delayed required-check enforcement after check creation.
 * Type: number. Units: attempts. Scope: one exact-candidate develop publication.
 * Consumers: integrateDevelop.
 * Rationale: six attempts permit short provider propagation without retrying a permanent rejection indefinitely.
 */
const REQUIRED_CHECK_PROPAGATION_ATTEMPTS=6;
/** Separate rejected publication attempts while GitHub propagates the required check.
 * Type: number. Units: milliseconds. Scope: one exact-candidate develop publication.
 * Consumers: integrateDevelop and pauseRequiredCheckPropagation.
 * Rationale: two seconds avoids a tight write loop; at most five waits add ten seconds before fresh evidence reads.
 */
const REQUIRED_CHECK_PROPAGATION_DELAY_MS=2000;

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
/** Limit the finite parallel-CI evaluator transition while all actual scanner implementations remain inherited.
 * Type: Map<string,string>. Units: exact repository-relative paths and regular-file Git blob SHAs. Scope: owner-dispatched protected-develop installation.
 * Consumers: inherited authority comparison after the workflow installation is independently authenticated.
 * Rationale: the evaluator transition is bound to independently qualified blobs while scanner implementations remain inherited.
 */
const AUTHORITY_UPDATE_PATHS=new Map([
    [PREPARATION_PATH,'fd4d95e7ad7d8a4494c29016b17b7c6b65ae82de'],
    ['scripts/audit.php','b09c00f41800de806f91a86477999921966e4419'],
    ['scripts/audit_lib.php','10fa4f72de1a624125221f8c4a05db6e8908b8c5'],
    ['scripts/audit_source_evidence.php','3881cc132866ccecc83cca1059f32b0070cd54f4'],
    ['scripts/audit_registry.php','4fb229c0be42dfd5d086c948a47f1b7e25dca398'],
    ['scripts/audit_php_registry.php','783544714d6f918b35e2c09c3a7016bd7a26b82d'],
]);
/** Seal the one generated companion already present in the exact qualified source tree.
 * Type: Map<string,string>. Units: one repository-relative regular-file path and Git blob SHA.
 * Scope: this finite parallel-CI transition only. Consumers: source-tree authentication and stage-2 leaf allowlist.
 * Rationale: the prepared manifest must remain byte-identical after the evaluator is restored, while other generated inputs stay inherited.
 */
const AUTHORITY_UPDATE_GENERATED=new Map([
    ['app/core-manifest.json','42a26c98cb69a39406fb34e7183343c0d65b8d09'],
]);
/** Retain the exact reviewed source and successful hosted run that own this finite evaluator update.
 * Type: Readonly<{source:string,tree:string,run:string,attempt:number,branch:string}>. Units: immutable Git, Actions and source-branch identities.
 * Scope: the single qualified parallel-CI authority transition. Consumers: protected installation authentication.
 * Rationale: the new FULL evaluator must be an independently retained qualified toolchain, not candidate-selected code.
 */
const AUTHORITY_UPDATE_SOURCE=Object.freeze({source:'ebde37ae7bd5c80ad1cb8733b7fb8fd2acb6cac1',
    tree:'4f01e2e5bf24bd5b34137a515075b4105889f87c',run:'38790736942',attempt:2,branch:'feature/ci_parallelization'});
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
const BASIC_PROFILE=['php-lint-changed','js-lint-changed','source-documentation-changed'];
/** Require every native owner of the existing complete integration matrix.
 * Type: string[]. Units: GitHub Actions job names. Scope: exact prepared integration candidate A.
 * Consumers: validateDevelopIntegration and its recorded successful native owner IDs.
 * Rationale: FULL must retain source, database, browser, package and aggregate owners before develop moves.
 */
const FULL_OWNERS=fullMatrixOwners();

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
 * @param {boolean} [authorityUpdate=false] Whether the independently integrated develop installation owns this run.
 * @param {string} [featureRef=''] Explicit owner-selected feature/main ref for the versioned qualification path.
 * @returns {boolean} True only for the exact selected workflow/ref or the historical protected-develop installation.
 */
function trustedWorkflowRef(repository,workflowRef,authorityUpdate=false,featureRef='') {
    const branch=featureRef ? featureRef.slice('refs/heads/'.length) : (authorityUpdate ? 'develop' : 'main');
    return workflowRef===WORKFLOW_PATH+'@refs/heads/'+branch
        || workflowRef===repository+'/'+WORKFLOW_PATH+'@refs/heads/'+branch;
}

/** Resolve the exact server-selected workflow branch without silently substituting main.
 * @param {WorkflowContext} context Actual workflow ref and qualification mode.
 * @returns {string} Selected feature/main branch, or the historical protected-develop branch.
 */
function integrationBranch(context) {
    return context.featureQualification ? context.ref.slice('refs/heads/'.length) : (context.authorityUpdate ? 'develop' : 'main');
}

/** Admit the owner's selected feature workflow while keeping legacy authority modes explicit.
 * @param {WorkflowContext} context Actual workflow ref and versioned qualification mode.
 * @returns {boolean} Whether the ref belongs to the selected supported integration path.
 */
function trustedIntegrationRef(context) {
    return context.featureQualification
        ? context.ref==='refs/heads/main' || context.ref.startsWith('refs/heads/feature/') && validBranchName(context.ref.slice(11))
        : context.ref===(context.authorityUpdate ? 'refs/heads/develop' : 'refs/heads/main');
}

/** Require explicit maintainer selection before a feature-authored workflow can publish protected integration.
 * @param {WorkflowContext} context Exact actual workflow identity.
 * @param {Record<string,unknown>} run Independently fetched current run including actor and triggering actor.
 * @param {GithubApi} call Read-only provider adapter.
 * @returns {void} Throws for an unapproved actor or foreign feature workflow; ordinary historical main modes remain supported.
 */
function requireFeatureDispatch(context,run,call) {
    if (!context.featureQualification) return;
    const owner=context.repository.split('/')[0];
    if (run.actor?.login!==owner || run.triggering_actor?.login!==owner
        || !['admin','maintain'].includes(call(`repos/${context.repository}/collaborators/${encodeURIComponent(owner)}/permission`)?.permission)) {
        throw new Error('BLOCKED feature integration requires explicit owner workflow selection.');
    }
}

/** GitHub API adapter supporting exact paths, methods and JSON payloads.
 * @typedef {function(string,string=,(Record<string,unknown>|null)=):Record<string,unknown>} GithubApi
 */
/** Integration request bound to the user-selected feature source and observed develop lease.
 * @typedef {{branch:string,sourceSha:string,candidateSha:string,expectedDevelopSha:string,sourceBase:string,basicRunId:number|string,basicRunAttempt:number|string,integrationCheckId?:number,matrixCheckId?:number,basicArtifactId?:number,basicArtifactDigest?:string,qualifiedRun?:string}} IntegrationRequest
 */
/** Retained protocol shared by normal integration and no-ref-write owner recovery.
 * @typedef {{schema_version:1|2,repository:string,source_branch:string,source_sha:string,candidate_sha:string,candidate_tree:string,expected_develop_sha:string,source_base:string,workflow_sha:string,workflow_path:string,run_id:string,run_attempt:string,basic_profile:string[],native_owners:Array<{id:number,name:string,status:string,conclusion:string}>,check_run_id?:number,matrix_check_run_id?:number,matrix_status_id?:number,feature_qualification?:import('./feature-qualification.mjs').FeatureQualification,recovery_mode?:'existing-develop',observed_develop_sha?:string,ref_write?:false,authority_update?:{anchor:ReturnType<typeof loadIntegrationQualification>,inherited_basic:Record<string,unknown>}}} IntegrationEvidenceRecord
 */
/** Protected workflow identity taken from the current GitHub Actions environment.
 * @typedef {{repository:string,workflowPath:string,workflowRef:string,eventName:string,ref:string,runId:number|string,runAttempt:number|string,headSha:string,authorityUpdate?:boolean,featureQualification?:boolean,stage?:'execution'|'publication',qualifiedRun?:string}} WorkflowContext
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
 * @param {boolean} [authorityUpdate=false] Permit only the bounded evaluator transition after authenticating protected develop authority.
 * @returns {void} Returns only when all pinned BASIC evaluator files and source-contract leaves match both trees.
 */
function requireInheritedBasicAuthority(call,repository,sourceTree,baseTree,authorityUpdate=false) {
    const source=completeTree(call,repository,sourceTree),base=completeTree(call,repository,baseTree);
    for (const path of BASIC_AUTHORITY_PATHS) {
        if (authorityUpdate && AUTHORITY_UPDATE_PATHS.has(path)
            && source.get(path)==='100644:blob:'+AUTHORITY_UPDATE_PATHS.get(path) && base.has(path)) continue;
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
 * @param {string} sourceSha Selected one-commit source S, or final A after automatic squash and fresh BASIC.
 * @param {string} candidateSha Prepared candidate A.
 * @param {string} developSha Observed develop P.
 * @param {boolean} recovery Whether the source is already the exact protected develop candidate; its own authority is then retained.
 * @param {boolean} [authorityUpdate=false] Require inherited scanners while allowing the reviewed evaluator transition.
 * @param {boolean} [featureQualification=false] Use independent main-owned BASIC instead of requiring candidate evaluator identity.
 * @returns {{tree:string}} Candidate tree SHA.
 */
function verifyAncestry(call,repository,sourceSha,candidateSha,developSha,recovery=false,authorityUpdate=false,featureQualification=false) {
    const source=call('repos/'+repository+'/commits/'+sourceSha);
    if (source?.sha!==sourceSha || source.commit?.tree?.sha===undefined
        || source.parents?.length!==1 || source.parents[0]?.sha!==developSha) {
        throw new Error('BLOCKED source S must have develop P as its sole parent.');
    }
    const base=call('repos/'+repository+'/commits/'+developSha);
    if (base?.sha!==developSha || typeof base.commit?.tree?.sha!=='string') throw new Error('BLOCKED develop P tree is unavailable.');
    if (authorityUpdate) {
        const sourceTree=completeTree(call,repository,source.commit.tree.sha);
        const baseTree=completeTree(call,repository,base.commit.tree.sha);
        requireAuthorityUpdateSourcePaths(sourceTree,baseTree);
    }
    // Recovery trusts only the exact already-protected candidate, not a new feature's authority edits.
    if (!featureQualification) requireInheritedBasicAuthority(call,repository,source.commit.tree.sha,recovery ? source.commit.tree.sha : base.commit.tree.sha,authorityUpdate);
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

/** Reject every stage-2 source delta outside the exact sealed evaluator and manifest blobs.
 * @param {Map<string,string>} source Complete leaf path identities for S, including mode, object type and SHA.
 * @param {Map<string,string>} base Complete leaf path identities for protected develop P.
 * @returns {void} Returns only when every changed path is an approved regular-file blob with its sealed identity.
 */
export function requireAuthorityUpdateSourcePaths(source,base) {
    if (!(source instanceof Map) || !(base instanceof Map)) throw new Error('BLOCKED complete authority-update source trees are required.');
    const allowed=new Map([...AUTHORITY_UPDATE_PATHS,...AUTHORITY_UPDATE_GENERATED]);
    for (const path of new Set([...source.keys(),...base.keys()])) {
        if (source.get(path)===base.get(path)) continue;
        if (!allowed.has(path) || !base.has(path) || source.get(path)!=='100644:blob:'+allowed.get(path)) {
            throw new Error('BLOCKED authority-update source changes a path outside its exact sealed evaluator and manifest blobs: '+path);
        }
    }
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

/** Authenticate a protected-develop publisher through its prior successful main-owned installation.
 * @param {WorkflowContext} context Actual server-bound current workflow context.
 * @param {string} repository Exact owner/repository identity.
 * @param {GithubApi} call Authenticated independent provider reader.
 * @returns {ReturnType<typeof loadIntegrationQualification>|null} Original authenticated anchor, or null for ordinary main publication.
 */
function requireAuthorityUpdateInstallation(context,repository,call) {
    if (context.authorityUpdate!==undefined && typeof context.authorityUpdate!=='boolean') {
        throw new Error('BLOCKED CI authority installation mode must be boolean.');
    }
    if (!context.authorityUpdate) return null;
    const owner=repository.split('/')[0];
    const run=call(`repos/${repository}/actions/runs/${context.runId}`);
    const develop=call(`repos/${repository}/git/ref/heads/develop`);
    const permission=call(`repos/${repository}/collaborators/${encodeURIComponent(owner)}/permission`);
    if (context.ref!=='refs/heads/develop' || run?.head_branch!=='develop' || run.head_sha!==context.headSha
        || develop?.object?.sha!==context.headSha || run.actor?.login?.toLowerCase()!==owner.toLowerCase()
        || run.triggering_actor?.login?.toLowerCase()!==owner.toLowerCase()
        || !['admin','maintain'].includes(permission?.permission)) {
        throw new Error('BLOCKED CI authority update requires owner dispatch from the unchanged protected develop installation.');
    }
    requireOwnerRuleset(call,repository,'develop','Reviewed main-to-develop release reconciliation','Complete required CI matrix');
    const anchor=loadIntegrationQualification(repository,context.headSha,{api:path=>call(path)});
    if (Object.hasOwn(anchor.evidence.payload,'authority_update') || anchor.identity.candidate_sha!==context.headSha) {
        throw new Error('BLOCKED CI authority installation must terminate at one main-owned integration anchor.');
    }
    requireCurrentWorkflowOwners(repository,context.headSha,path=>call(path));
    requireAuthorityUpdateSource(repository,call);
    return anchor;
}

/** Authenticate the exact successful maintenance FULL that owns the sealed evaluator input map.
 * @param {string} repository Exact configured repository identity.
 * @param {GithubApi} call Independent GitHub provider reader.
 * @returns {void} Returns only for complete retained exact-source qualification and its immutable evaluator blobs.
 */
export function requireAuthorityUpdateSource(repository,call) {
    if (repository!=='klusik/PHP_gallery') throw new Error('BLOCKED evaluator transition belongs to its original repository only.');
    const selected=AUTHORITY_UPDATE_SOURCE;
    const source=call(`repos/${repository}/commits/${selected.source}`);
    const run=call(`repos/${repository}/actions/runs/${selected.run}/attempts/${selected.attempt}`);
    if (source?.sha!==selected.source || source.commit?.tree?.sha!==selected.tree
        || run?.id!==Number(selected.run) || run.run_attempt!==selected.attempt || run.head_sha!==selected.source
        || run.path!==PREPARATION_PATH || run.event!=='workflow_dispatch' || run.head_branch!==selected.branch
        || run.status!=='completed' || run.conclusion!=='success'
        || run.actor?.login?.toLowerCase()!==repository.split('/')[0].toLowerCase()) {
        throw new Error('BLOCKED sealed evaluator source qualification is missing or inconsistent.');
    }
    const tree=completeTree(call,repository,selected.tree);
    for (const [path,blob] of AUTHORITY_UPDATE_PATHS) {
        if (tree.get(path)!=='100644:blob:'+blob) throw new Error('BLOCKED sealed evaluator blob differs from its qualified source.');
    }
    for (const [path,blob] of AUTHORITY_UPDATE_GENERATED) {
        if (tree.get(path)!=='100644:blob:'+blob) throw new Error('BLOCKED generated companion differs from its qualified source tree.');
    }
    const jobs=completeRows(call,`repos/${repository}/actions/runs/${selected.run}/attempts/${selected.attempt}/jobs`,'jobs');
    for (const name of fullMatrixOwners(3).map(label=>'Full CI for the published prepared SHA / '+label)) {
        const matches=jobs.filter(job=>job.name===name && job.run_id===Number(selected.run) && job.run_attempt===selected.attempt);
        if (matches.length!==1 || matches[0].status!=='completed' || matches[0].conclusion!=='success') {
            throw new Error('BLOCKED sealed evaluator source has a missing or unsuccessful native FULL owner: '+name);
        }
    }
    exactCheck(call,repository,selected.source,'Candidate qualification',
        `candidate:${selected.run}:${selected.attempt}:${selected.source}`);
}

/** Validate inherited central BASIC evidence transported by the current trusted preparation job.
 * @param {import('./trusted-basic.mjs').TrustedBasicEvidence} report Decoded provider-authenticated independent BASIC record.
 * @param {IntegrationRequest|{sourceSha:string,expectedDevelopSha:string}} request Exact source and protected-base identities.
 * @param {WorkflowContext} context Current independently anchored publisher identity.
 * @param {string} tree Original source tree qualified by inherited BASIC.
 * @returns {import('./trusted-basic.mjs').TrustedBasicEvidence} Complete accepted record; unknown, missing or mismatched evidence throws.
 */
export function requireTrustedBasicEvidence(report,request,context,tree) {
    const fields=['schema_version','authority_sha','source_sha','source_tree','comparison_base','profile','suites',
        'successful','report_sha256','root_binding'].sort();
    if (!report || JSON.stringify(Object.keys(report).sort())!==JSON.stringify(fields)
        || report.schema_version!==1 || report.authority_sha!==context.headSha
        || report.source_sha!==request.sourceSha || report.source_tree!==tree
        || report.comparison_base!==request.expectedDevelopSha || !context.featureQualification && report.authority_sha!==request.expectedDevelopSha
        || report.profile!=='basic' || report.successful!==true
        || JSON.stringify(report.suites)!==JSON.stringify(['php-lint','js-lint','source-documentation-changed'])
        || !/^sha256:[a-f0-9]{64}$/.test(report.report_sha256 ?? '')
        || report.root_binding!=='trusted-evaluator-candidate-root-v1') {
        throw new Error('BLOCKED inherited BASIC authority evidence is missing or inconsistent.');
    }
    return report;
}

/** Reload the current attempt's authenticated preparation archive before publishing protected evidence.
 * @param {IntegrationRequest|{sourceSha:string,expectedDevelopSha:string,basicArtifactId?:number,basicArtifactDigest?:string}} request Exact source and independent BASIC provider artifact identities.
 * @param {WorkflowContext} context Independently installed publisher identity.
 * @param {GithubApi} call Authenticated GitHub provider reader.
 * @returns {import('./trusted-basic.mjs').TrustedBasicEvidence & {artifact_id:number,artifact_sha256:string}} Independently successful BASIC report and provider archive bound to source S.
 */
function loadTrustedBasicEvidence(request,context,call) {
    const transport=readCoverageArtifact(context.repository,request.basicArtifactId,String(context.runId),request.basicArtifactDigest,
        'develop-integration-inherited-basic',['trusted-basic.json','trusted-basic.json.report.json'],{api:path=>call(path)});
    const source=call(`repos/${context.repository}/commits/${request.sourceSha}`);
    const proof=JSON.parse(transport.entries.get('trusted-basic.json').toString('utf8'));
    const bytes=transport.entries.get('trusted-basic.json.report.json');
    const rebuilt=bindTrustedBasicReport(JSON.parse(bytes.toString('utf8')),
        {authority:context.headSha,source:request.sourceSha,tree:source.commit.tree.sha,base:request.expectedDevelopSha},bytes);
    if (JSON.stringify(proof)!==JSON.stringify(rebuilt)) throw new Error('BLOCKED inherited BASIC report bytes differ from retained proof.');
    requireTrustedBasicEvidence(proof,request,context,source.commit.tree.sha);
    return {...proof,artifact_id:request.basicArtifactId,artifact_sha256:request.basicArtifactDigest};
}

/** Resolve the selected branch head, its direct develop parent and newest successful BASIC attempt.
 * @param {string} branch User-selected feature branch name.
 * @param {WorkflowContext} context Current main-owned workflow identity.
 * @param {string} repository Configured owner/repository.
 * @param {GithubApi} call Authenticated GitHub API adapter.
 * @returns {{branch:string,sourceSha:string,expectedDevelopSha:string,sourceBase:string,basicRunId:string,basicRunAttempt:string}} Exact selected source and evidence inputs for preparation.
 * @param {boolean} recovery Whether to authenticate owner recovery of an already-qualified, unchanged develop head.
 */
export function selectDevelopIntegration(branch,context,repository,call,recovery=false) {
    if (typeof recovery!=='boolean') throw new Error('BLOCKED recovery mode must be boolean.');
    if (recovery && context?.authorityUpdate) throw new Error('BLOCKED CI authority update cannot be combined with recovery.');
    if (!validBranchName(branch) || !branch.startsWith('feature/') || context?.repository!==repository
        || context.workflowPath!==WORKFLOW_PATH || !trustedWorkflowRef(repository,context.workflowRef,context.authorityUpdate,context.featureQualification ? context.ref : '')
        || context.eventName!=='workflow_dispatch' || !trustedIntegrationRef(context)
        || !/^\d+$/.test(String(context.runId)) || !/^\d+$/.test(String(context.runAttempt))
        || !/^[a-f0-9]{40}$/.test(context.headSha)) throw new Error('BLOCKED source selection is outside the main-owned workflow context.');
    const active=call('repos/'+repository+'/actions/runs/'+String(context.runId));
    if (String(active?.id)!==String(context.runId) || active?.path!==WORKFLOW_PATH || active?.head_branch!==integrationBranch(context)
        || active?.head_sha!==context.headSha || active?.event!=='workflow_dispatch' || active?.status!=='in_progress'
        || String(active?.run_attempt)!==String(context.runAttempt)) throw new Error('BLOCKED source selection workflow run is not current and active.');
    requireAuthorityUpdateInstallation(context,repository,call);
    requireFeatureDispatch(context,active,call);
    const sourceSha=call('repos/'+repository+'/git/ref/heads/'+encodedBranch(branch))?.object?.sha;
    const observedDevelopSha=call('repos/'+repository+'/git/ref/heads/develop')?.object?.sha;
    if (recovery) requireRecoveryOwner(call,repository,active,sourceSha,observedDevelopSha);
    const candidate=call('repos/'+repository+'/commits/'+sourceSha);
    const expectedDevelopSha=recovery ? candidate?.parents?.[0]?.sha : observedDevelopSha;
    if (!/^[a-f0-9]{40}$/.test(sourceSha ?? '') || !/^[a-f0-9]{40}$/.test(expectedDevelopSha ?? '')) {
        throw new Error('BLOCKED selected source or develop ref is unavailable.');
    }
    const source=call('repos/'+repository+'/commits/'+sourceSha);
    if (source?.sha!==sourceSha || source.parents?.length!==1 || source.parents[0]?.sha!==expectedDevelopSha) {
        throw new Error('BLOCKED selected source S must be exactly one commit above current develop P.');
    }
    const base=call('repos/'+repository+'/commits/'+expectedDevelopSha);
    if (base?.sha!==expectedDevelopSha || typeof base.commit?.tree?.sha!=='string') throw new Error('BLOCKED current develop P tree is unavailable.');
    if (context.authorityUpdate) requireAuthorityUpdateSourcePaths(
        completeTree(call,repository,source.commit.tree.sha),completeTree(call,repository,base.commit.tree.sha));
    if (!context.featureQualification) requireInheritedBasicAuthority(call,repository,source.commit.tree.sha,recovery ? source.commit.tree.sha : base.commit.tree.sha,context.authorityUpdate);
    if (context.qualifiedRun) loadFeatureExecution(repository,String(context.qualifiedRun),
        {candidate:sourceSha,tree:source.commit.tree.sha,base:expectedDevelopSha},{api:path=>call(path)});
    const runs=completeRows(call,'repos/'+repository+'/actions/workflows/candidate-preparation.yml/runs?branch='
        +encodeURIComponent(branch)+'&head_sha='+sourceSha,'workflow_runs');
    const exact=runs.filter(run=>run.head_branch===branch && run.head_sha===sourceSha
        && ['push','workflow_dispatch'].includes(run.event)).sort((left,right)=>Number(right.id)-Number(left.id));
    const latest=exact[0];
    if (!latest || !Number.isSafeInteger(latest.run_attempt) || latest.status!=='completed' || latest.conclusion!=='success') {
        throw new Error('BLOCKED selected source has no latest completed successful BASIC workflow run.');
    }
    validateBasicEvidence(call,repository,branch,sourceSha,latest.id,latest.run_attempt);
    if (recovery) requirePreviousFull(call,repository,sourceSha,latest);
    return {branch,sourceSha,expectedDevelopSha,sourceBase:expectedDevelopSha,
        basicRunId:String(latest.id),basicRunAttempt:String(latest.run_attempt)};
}

/** Check exact protected-workflow execution context and every required native matrix owner.
 * @param {IntegrationRequest} request Selected source and candidate identity.
 * @param {WorkflowContext} context Current GitHub Actions identity.
 * @param {string} repository Configured repository identity.
 * @param {GithubApi} call Authenticated GitHub API adapter.
 * @returns {{plan:{branch:string,oldSha:string,newSha:string},record:IntegrationEvidenceRecord}} Validated plan and immutable evidence record.
 * @param {boolean} recovery Whether to validate fresh FULL evidence for existing develop without planning a ref change.
 */
export function validateDevelopIntegration(request,context,repository,call,recovery=false) {
    if (typeof recovery!=='boolean') throw new Error('BLOCKED recovery mode must be boolean.');
    if (recovery && context?.authorityUpdate) throw new Error('BLOCKED CI authority update cannot be combined with recovery.');
    if (!request || !validBranchName(request.branch) || !/^feature\//.test(request.branch)
        || ![request.sourceSha,request.candidateSha,request.expectedDevelopSha,request.sourceBase].every(value=>/^[a-f0-9]{40}$/.test(value))
        || request.sourceBase!==request.expectedDevelopSha || context?.repository!==repository
        || !/^\d+$/.test(String(request.basicRunId)) || !/^\d+$/.test(String(request.basicRunAttempt))
        || context.workflowPath!==WORKFLOW_PATH || !trustedWorkflowRef(repository,context.workflowRef,context.authorityUpdate,context.featureQualification ? context.ref : '')
        || context.eventName!=='workflow_dispatch' || !trustedIntegrationRef(context)
        || !/^[a-f0-9]{40}$/.test(context.headSha) || !/^\d+$/.test(String(context.runId))
        || !/^\d+$/.test(String(context.runAttempt))) throw new Error('BLOCKED untrusted integration workflow identity or request.');
    const runId=String(context.runId),attempt=String(context.runAttempt);
    const currentRun=call('repos/'+repository+'/actions/runs/'+runId);
    if (String(currentRun?.id)!==runId || currentRun?.path!==WORKFLOW_PATH || currentRun?.event!=='workflow_dispatch'
        || currentRun?.head_branch!==integrationBranch(context) || currentRun?.head_sha!==context.headSha
        || currentRun?.status!=='in_progress' || String(currentRun?.run_attempt)!==attempt) {
        throw new Error('BLOCKED current publisher is not the exact active main-owned workflow attempt.');
    }
    const anchor=requireAuthorityUpdateInstallation(context,repository,call);
    requireFeatureDispatch(context,currentRun,call);
    const inheritedBasic=anchor || context.featureQualification ? loadTrustedBasicEvidence(request,context,call) : null;
    if (recovery) {
        requireRecoveryOwner(call,repository,currentRun,request.sourceSha,request.candidateSha);
        if (request.sourceSha!==request.candidateSha) throw new Error('BLOCKED recovery cannot create a generated candidate child.');
    }
    const heads=readHeads(call,repository,request.branch,request.candidateSha,recovery ? request.candidateSha : request.expectedDevelopSha);
    const ancestry=verifyAncestry(call,repository,request.sourceSha,request.candidateSha,request.expectedDevelopSha,recovery,context.authorityUpdate,context.featureQualification);
    const basicRuns=completeRows(call,'repos/'+repository+'/actions/workflows/candidate-preparation.yml/runs?branch='
        +encodeURIComponent(request.branch)+'&head_sha='+request.sourceSha,'workflow_runs');
    const matchingBasicRuns=basicRuns.filter(run=>run.head_branch===request.branch && run.head_sha===request.sourceSha
        && ['push','workflow_dispatch'].includes(run.event));
    const newestBasicRun=matchingBasicRuns.sort((left,right)=>Number(right.id)-Number(left.id))[0];
    if (!newestBasicRun || String(newestBasicRun.id)!==String(request.basicRunId)) {
        throw new Error('BLOCKED BASIC evidence is not from the newest retained run for this branch and S.');
    }
    validateBasicEvidence(call,repository,request.branch,request.sourceSha,request.basicRunId,request.basicRunAttempt);
    if (recovery) requirePreviousFull(call,repository,request.sourceSha,newestBasicRun);
    if (context.featureQualification) return validateFeatureIntegration(request,context,call,ancestry.tree,inheritedBasic,heads.develop);
    const jobs=completeRows(call,'repos/'+repository+'/actions/runs/'+runId+'/attempts/'+attempt+'/jobs','jobs');
    const owners=[];
    const auxiliaryNames=['Select squashed source','Prepare integration artifacts','Publish prepared integration candidate',
        ...(context.authorityUpdate ? ['Inherited BASIC authority'] : [])];
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
        'Publish develop integration','Develop integration / Authoritative release audit','Inherited BASIC authority']);
    if (jobs.some(job=>!allowed.has(job.name) || job.run_id!==Number(runId)
        || job.run_attempt!==Number(attempt) || job.workflow_name!=='Integrate into develop')
        || jobs.filter(job=>job.name==='Develop integration / Authoritative release audit').some(job=>
            job.status!=='completed' || job.conclusion!=='skipped')
        || (!context.authorityUpdate && jobs.filter(job=>job.name==='Inherited BASIC authority').some(job=>
            job.status!=='completed' || job.conclusion!=='skipped'))) {
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
    if (recovery) Object.assign(record,{recovery_mode:'existing-develop',observed_develop_sha:heads.develop,ref_write:false});
    if (anchor) record.authority_update={anchor,inherited_basic:inheritedBasic};
    return {plan:{branch:'develop',oldSha:heads.develop,newSha:request.candidateSha},record};
}

/** Bind independent BASIC and a complete native execution to a separate main-owned publication attempt.
 * @param {IntegrationRequest} request Exact final candidate, base and optional sealed execution run.
 * @param {WorkflowContext} context Current main-owned workflow and execution/publication stage.
 * @param {GithubApi} call Independent GitHub provider reader.
 * @param {string} tree Complete final candidate input tree.
 * @param {import('./feature-qualification.mjs').FeatureBasic} basic Independently reproduced BASIC proof and archive identity.
 * @param {string} develop Observed protected develop lease.
 * @returns {{plan:{branch:string,oldSha:string,newSha:string},record:IntegrationEvidenceRecord}} Versioned feature qualification and guarded publication plan.
 */
function validateFeatureIntegration(request,context,call,tree,basic,develop) {
    if (context.authorityUpdate || request.sourceSha!==request.candidateSha) {
        throw new Error('BLOCKED feature qualification requires the final prepared source and ordinary main authority.');
    }
    const repository=context.repository,runId=String(context.runId),attempt=String(context.runAttempt);
    const jobs=completeRows(call,`repos/${repository}/actions/runs/${runId}/attempts/${attempt}/jobs`,'jobs');
    let execution={run_id:runId,run_attempt:attempt,workflow_sha:context.headSha};
    let owners=jobs.filter(job=>FULL_OWNERS.some(name=>job.name==='Develop integration / '+name))
        .map(job=>({id:job.id,name:job.name,status:job.status,conclusion:job.conclusion}));
    if (request.qualifiedRun) {
        const prior=loadFeatureExecution(repository,String(request.qualifiedRun),
            {candidate:request.candidateSha,tree,base:request.expectedDevelopSha},{api:path=>call(path)});
        execution=prior.record.feature_qualification.execution;owners=prior.record.native_owners;
    }
    verifyFeatureOwners(repository,execution,owners,path=>call(path));
    verifyFeatureBasic(basic,{authority:context.headSha,source:request.sourceSha,tree,
        base:request.expectedDevelopSha,run:runId},repository,{api:path=>call(path)});
    const auxiliary=['Select squashed source','Prepare integration artifacts','Publish prepared integration candidate','Inherited BASIC authority'];
    if (context.stage!=='execution') auxiliary.push('Retain integration execution');
    for (const name of auxiliary) {
        const matches=jobs.filter(job=>job.name===name);
        if (matches.length!==1 || matches[0].run_id!==Number(runId) || matches[0].run_attempt!==Number(attempt)
            || matches[0].workflow_name!=='Integrate into develop' || matches[0].status!=='completed'
            || matches[0].conclusion!=='success') throw new Error('BLOCKED unsuccessful feature qualification stage: '+name);
    }
    const stage=context.stage==='execution'?'Retain integration execution':'Publish develop integration';
    const active=jobs.filter(job=>job.name===stage);
    if (active.length!==1 || !['queued','in_progress'].includes(active[0].status)) {
        throw new Error('BLOCKED feature qualification stage is not the current active native job.');
    }
    const allowed=new Set([...auxiliary,stage,'Publish develop integration','Retain integration execution',
        ...FULL_OWNERS.map(name=>'Develop integration / '+name),'Develop integration / Authoritative release audit']);
    if (request.qualifiedRun) allowed.add('Develop integration');
    if (jobs.some(job=>!allowed.has(job.name))) throw new Error('BLOCKED unexpected native feature qualification job.');
    const record={schema_version:2,repository,source_branch:request.branch,source_sha:request.sourceSha,
        candidate_sha:request.candidateSha,candidate_tree:tree,expected_develop_sha:request.expectedDevelopSha,
        source_base:request.sourceBase,workflow_sha:context.headSha,workflow_path:WORKFLOW_PATH,
        run_id:runId,run_attempt:attempt,basic_profile:BASIC_PROFILE,native_owners:owners,
        feature_qualification:{schema_version:1,input_tree:tree,basic,execution}};
    return {plan:{branch:'develop',oldSha:develop,newSha:request.candidateSha},record};
}

/** Require explicit repository-owner recovery of the exact already-protected source candidate.
 * @param {GithubApi} call Authenticated GitHub API adapter.
 * @param {string} repository Exact owner/repository.
 * @param {{actor?:{login?:string},triggering_actor?:{login?:string}}} run Current server-owned workflow actor and triggering actor identities.
 * @param {string} source Exact retained source branch candidate.
 * @param {string} develop Current protected develop candidate.
 * @returns {void} Throws for unauthorized dispatch or any source/develop divergence.
 */
function requireRecoveryOwner(call,repository,run,source,develop) {
    const owner=repository.split('/')[0];
    if (source!==develop || run.actor?.login?.toLowerCase()!==owner.toLowerCase()
        || run.triggering_actor?.login?.toLowerCase()!==owner.toLowerCase()) {
        throw new Error('BLOCKED recovery requires owner dispatch and source exactly equal to existing develop.');
    }
    const permission=call('repos/'+repository+'/collaborators/'+encodeURIComponent(owner)+'/permission');
    if (!['admin','maintain'].includes(permission?.permission)) throw new Error('BLOCKED recovery owner permission is unavailable.');
}

/** Authenticate all mandatory native owners of the newest exact-source maintenance FULL run.
 * @param {GithubApi} call Authenticated GitHub API adapter.
 * @param {string} repository Exact owner/repository.
 * @param {string} candidate Existing protected candidate, also the original source event SHA.
 * @param {{id:number,run_attempt:number,head_branch:string}} retained Newest successful source workflow identity, independently reread by attempt.
 * @returns {void} Throws when the full source workflow, native owners or retained attempt is incomplete or red.
 */
function requirePreviousFull(call,repository,candidate,retained) {
    const run=call(`repos/${repository}/actions/runs/${retained.id}/attempts/${retained.run_attempt}`);
    if (run.id!==retained.id || run.run_attempt!==retained.run_attempt || run.head_sha!==candidate
        || run.head_branch!==retained.head_branch || run.path!=='.github/workflows/candidate-preparation.yml'
        || run.event!=='workflow_dispatch' || run.status!=='completed' || run.conclusion!=='success') {
        throw new Error('BLOCKED recovery needs the newest exact-candidate successful maintenance FULL attempt.');
    }
    const jobs=completeRows(call,`repos/${repository}/actions/runs/${run.id}/attempts/${run.run_attempt}/jobs`,'jobs');
    for (const name of [...FULL_OWNERS.map(label=>'Full CI for the published prepared SHA / '+label),
        'Basic source checks','Prepare source candidate at event SHA','Reject superseded qualification']) {
        const matches=jobs.filter(job=>job.name===name);
        if (matches.length!==1 || matches[0].run_id!==run.id || matches[0].run_attempt!==run.run_attempt
            || matches[0].status!=='completed' || matches[0].conclusion!=='success') {
            throw new Error('BLOCKED recovery maintenance FULL owner is missing, duplicate or red: '+name);
        }
    }
    for (const name of ['Candidate qualification','Complete required CI matrix']) {
        exactCheck(call,repository,candidate,name,`candidate:${run.id}:${run.run_attempt}:${candidate}`);
    }
}

/** Publish freshly qualified integration metadata for existing develop without writing any Git ref.
 * @param {IntegrationRequest} request Exact existing source candidate and recorded historical parent.
 * @param {WorkflowContext} context Current main-owned workflow identity.
 * @param {string} repository Exact owner/repository.
 * @param {GithubApi} call Authenticated GitHub API adapter permitting check metadata, never ref mutation.
 * @returns {{branch:string,sha:string,record:IntegrationEvidenceRecord}} Fresh exact-candidate checks and backward-compatible retained evidence.
 */
export function recoverDevelop(request,context,repository,call) {
    const checked=validateDevelopIntegration(request,context,repository,call,true);
    const externalId=`integration:${context.runId}:${context.runAttempt}:${request.candidateSha}`;
    const ids=[];
    for (const name of ['Develop integration qualification','Complete required CI matrix']) {
        readHeads(call,repository,request.branch,request.candidateSha,request.candidateSha);
        const check=call(`repos/${repository}/check-runs`,'POST',{name,head_sha:request.candidateSha,
            external_id:externalId,status:'completed',conclusion:'success',output:{title:name,
                summary:'Owner recovery: existing develop independently qualified by fresh main-owned FULL; no Git ref was written.'}});
        if (!Number.isSafeInteger(check?.id) || check.id<1) throw new Error('BLOCKED recovery check publication was not confirmed.');
        ids.push(check.id);
    }
    readHeads(call,repository,request.branch,request.candidateSha,request.candidateSha);
    return {branch:'develop',sha:request.candidateSha,record:{...checked.record,check_run_id:ids[0],matrix_check_run_id:ids[1]}};
}

/** Wait for provider-side required-check enforcement before revalidating publication evidence.
 * @param {number} milliseconds Delay between rejected publication attempts in milliseconds.
 * @returns {void} Resumes the synchronous publisher after the bounded delay.
 */
function pauseRequiredCheckPropagation(milliseconds) {
    console.warn('Waiting '+milliseconds+' ms for GitHub required-check propagation before revalidating integration.');
    Atomics.wait(new Int32Array(new SharedArrayBuffer(4)),0,0,milliseconds);
}

/** Publish the standard commit status for already verified exact-candidate FULL evidence.
 * @param {string} repository Validated owner/repository identity.
 * @param {string} candidate Exact immutable FULL-qualified commit SHA.
 * @param {string} runId Actual verified integration workflow run ID.
 * @param {GithubApi} call Publisher's GitHub Actions provider with statuses:write.
 * @returns {{id:number,targetUrl:string}} Confirmed Actions-created status identity and its bound original workflow URL; unavailable or altered creation throws.
 */
export function publishIntegrationStatus(repository,candidate,runId,call) {
    if (!/^[\w.-]+\/[\w.-]+$/.test(repository) || !/^[a-f0-9]{40}$/.test(candidate)
        || !/^[1-9]\d*$/.test(runId)) throw new Error('BLOCKED invalid integration status identity.');
    const targetUrl=`https://github.com/${repository}/actions/runs/${runId}`;
    // Dispatch check suites can be visible through Checks REST without entering the branch-rule rollup.
    // Publish the same real qualification through Commit Statuses; neither representation runs or replaces FULL.
    const status=call(`repos/${repository}/statuses/${candidate}`,'POST',{state:'success',
        context:'Complete required CI matrix',target_url:targetUrl,
        description:'All required native FULL owners verified for this exact commit.'});
    if (!Number.isSafeInteger(status?.id) || status.id<1 || status.creator?.login!=='github-actions[bot]'
        || status.state!=='success' || status.context!=='Complete required CI matrix' || status.target_url!==targetUrl) {
        throw new Error('BLOCKED integration commit status creation was not confirmed.');
    }
    return {id:status.id,targetUrl};
}

/** Require the original successful Actions commit status before each protected ref update.
 * @param {string} repository Exact owner/repository identity.
 * @param {string} candidate Exact qualified commit whose combined status is read.
 * @param {{id:number,targetUrl:string}} expected Original status ID and immutable workflow link.
 * @param {GithubApi} call Independently read current provider metadata.
 * @returns {void} Returns only for the unchanged unique latest Actions-created status ID, successful state and workflow URL; missing or altered status throws.
 */
export function requireIntegrationStatus(repository,candidate,expected,call) {
    const statuses=completeRows(call,`repos/${repository}/commits/${candidate}/status`,'statuses')
        .filter(status=>status.context==='Complete required CI matrix');
    // Combined Status omits creator. Creation authenticated it; the immutable status ID binds that same publisher.
    if (statuses.length!==1 || statuses[0].id!==expected.id || statuses[0].state!=='success'
        || statuses[0].target_url!==expected.targetUrl) {
        throw new Error('BLOCKED integration commit status is missing, stale, failed or from another publisher.');
    }
}

/** Publish exact-candidate checks/status and retry only explicit propagation rejection with fresh evidence.
 * @param {IntegrationRequest} request Selected source and candidate identity.
 * @param {WorkflowContext} context Current protected workflow identity.
 * @param {string} repository Configured repository.
 * @param {GithubApi} call Authenticated GitHub API adapter.
 * @param {function(number):void} [pause=pauseRequiredCheckPropagation] Synchronous delay accepting milliseconds; fixtures inject an inert implementation.
 * @returns {{branch:string,sha:string,record:IntegrationEvidenceRecord}} Completed synchronization and attached integration proof.
 * @throws {Error} Rejects stale or invalid evidence, changed refs/checks, unconfirmed writes, other API failures or exhausted propagation retries.
 */
export function integrateDevelop(request,context,repository,call,pause=pauseRequiredCheckPropagation) {
    let checked=validateDevelopIntegration(request,context,repository,call);
    const runId=String(context.runId),attempt=String(context.runAttempt),externalId='integration:'+runId+':'+attempt+':'+request.candidateSha;
    const makeCheck=name=>call('repos/'+repository+'/check-runs','POST',{name,head_sha:request.candidateSha,
        external_id:externalId,status:'completed',conclusion:'success',output:{title:name,summary:'Exact source, ancestry and mandatory native owner evidence verified.'}});
    const integrationId=makeCheck('Develop integration qualification')?.id;
    const matrixId=makeCheck('Complete required CI matrix')?.id;
    if (!Number.isSafeInteger(integrationId) || !Number.isSafeInteger(matrixId)) throw new Error('BLOCKED required check creation was not confirmed.');
    const matrixStatus=publishIntegrationStatus(repository,request.candidateSha,runId,call);
    for (let attempt=1;attempt<=REQUIRED_CHECK_PROPAGATION_ATTEMPTS;attempt++) {
        if (attempt>1) checked=validateDevelopIntegration(request,context,repository,call);
        for (const [name,id] of [['Develop integration qualification',integrationId],['Complete required CI matrix',matrixId]]) {
            const current=exactCheck(call,repository,request.candidateSha,name,externalId);
            if (current.id!==id) throw new Error('BLOCKED required check identity changed before publication.');
        }
        requireIntegrationStatus(repository,request.candidateSha,matrixStatus,call);
        const fresh=readHeads(call,repository,request.branch,request.candidateSha,request.expectedDevelopSha);
        if (fresh.develop!==checked.plan.oldSha) throw new Error('BLOCKED develop changed before fast-forward write.');
        let updated;
        try {
            updated=call('repos/'+repository+'/git/refs/heads/develop','PATCH',{sha:request.candidateSha,force:false});
        } catch (error) {
            // Only the observed, sole missing-context rejection proves no ref write occurred.
            // Mixed rule violations, transport failures and ambiguous responses remain fatal.
            const propagation=error instanceof Error && /^GitHub API request failed: gh: Repository rule violations found\s+Required status check "Complete required CI matrix" is expected\.\s+\(HTTP 422\)\s*$/.test(error.message);
            if (!propagation || attempt===REQUIRED_CHECK_PROPAGATION_ATTEMPTS) throw error;
            readHeads(call,repository,request.branch,request.candidateSha,request.expectedDevelopSha);
            pause(REQUIRED_CHECK_PROPAGATION_DELAY_MS);
            continue;
        }
        if (updated?.object?.sha!==request.candidateSha) throw new Error('BLOCKED GitHub did not confirm the non-forced develop fast-forward.');
        const after=call('repos/'+repository+'/git/ref/heads/develop');
        if (after?.object?.sha!==request.candidateSha) throw new Error('BLOCKED develop moved after fast-forward publication.');
        const record={...checked.record,check_run_id:integrationId,matrix_check_run_id:matrixId,matrix_status_id:matrixStatus.id};
        return {branch:'develop',sha:request.candidateSha,record};
    }
    throw new Error('BLOCKED required-check propagation attempts exhausted.');
}

/** Validate prepared artifacts and publish a prepared feature candidate.
 * @param {{repository:string,branch:string,sourceSha:string,expectedDevelopSha:string,artifactId:number,artifactDigest:string,runId:number|string,autoSquashGenerated?:boolean,basicArtifactId?:number,basicArtifactDigest?:string}} request Exact selected S/P, generated metadata and independent BASIC archive for authority updates. autoSquashGenerated=true creates a prepared commit directly above expectedDevelopSha; omitted/false retains a child of sourceSha.
 * @param {WorkflowContext} context Main-owned publisher identity.
 * @param {GithubApi} call Authenticated synchronous GitHub API adapter.
 * @param {{api?:function(string):Record<string,unknown>,download?:function(string,number):Buffer}} adapters Optional independent provider and archive transports for inert fixtures.
 * @returns {{candidateSha:string,candidateTree:string,artifactId:number,artifactDigest:string}} Published candidate identity.
 * @throws {Error} Rejects invalid inputs, unsupported squash modes, missing source metadata or failed publication checks.
 */
export function publishPreparedCandidate(request,context,call,adapters={}) {
    if (context?.repository!==request?.repository || context.workflowPath!==WORKFLOW_PATH || !trustedWorkflowRef(request.repository,context.workflowRef,context.authorityUpdate,context.featureQualification ? context.ref : '')
        || context.eventName!=='workflow_dispatch' || !trustedIntegrationRef(context)
        || String(request?.runId)!==String(context.runId) || !Number.isSafeInteger(request.artifactId)
        || !/^sha256:[a-f0-9]{64}$/.test(request.artifactDigest) || !validBranchName(request.branch)
        || !/^feature\//.test(request.branch) || !/^[a-f0-9]{40}$/.test(request.sourceSha)
        || !/^[a-f0-9]{40}$/.test(request.expectedDevelopSha)) throw new Error('BLOCKED artifact publisher is outside the trusted main workflow context.');
    const {repository,branch,sourceSha}=request;
    const ownRun=call('repos/'+repository+'/actions/runs/'+String(context.runId));
    if (String(ownRun?.id)!==String(context.runId) || ownRun?.path!==WORKFLOW_PATH || ownRun?.head_branch!==integrationBranch(context)
        || ownRun?.head_sha!==context.headSha || ownRun?.event!=='workflow_dispatch' || ownRun?.status!=='in_progress'
        || String(ownRun?.run_attempt)!==String(context.runAttempt)) {
        throw new Error('BLOCKED candidate publisher is not this active main-owned workflow run.');
    }
    requireAuthorityUpdateInstallation(context,request.repository,call);
    requireFeatureDispatch(context,ownRun,call);
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
    if (request.autoSquashGenerated!==undefined
        && typeof request.autoSquashGenerated!=='boolean') {
        throw new Error('BLOCKED automatic squash flag must be a boolean.');
    }

    const autoSquashGenerated=request.autoSquashGenerated===true;

    if (autoSquashGenerated && context.authorityUpdate) {
        throw new Error('BLOCKED automatic squash is unavailable during a CI authority update.');
    }

    if (autoSquashGenerated
        && (typeof source.commit?.message!=='string'
            || source.commit.message.trim()==='')) {
        throw new Error('BLOCKED original source commit message is unavailable.');
    }
    if (typeof baseTree!=='string') throw new Error('BLOCKED source tree unavailable.');
    if (context.authorityUpdate) {
        const base=call(`repos/${repository}/commits/${request.expectedDevelopSha}`);
        if (base?.sha!==request.expectedDevelopSha || typeof base.commit?.tree?.sha!=='string') {
            throw new Error('BLOCKED authority-update develop base tree is unavailable.');
        }
        requireAuthorityUpdateSourcePaths(completeTree(call,repository,baseTree),completeTree(call,repository,base.commit.tree.sha));
        requireInheritedBasicAuthority(call,repository,baseTree,base.commit.tree.sha,true);
        loadTrustedBasicEvidence({...request},context,call);
    }
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
    const parentSha=autoSquashGenerated
        ? request.expectedDevelopSha
        : sourceSha;

    const commitMessage=autoSquashGenerated
        ? `${source.commit.message}\n\nPrepared-From: ${sourceSha}`
        : 'Prepare generated state for develop integration';

    const commit=call('repos/'+repository+'/git/commits','POST',{
        message:commitMessage,
        tree:tree.sha,
        parents:[parentSha],
    });
    if (!/^[a-f0-9]{40}$/.test(commit?.sha ?? '')) throw new Error('BLOCKED generated candidate commit creation failed.');
    const latest=call('repos/'+repository+'/git/ref/heads/'+encodedBranch(branch));
    if (latest?.object?.sha!==sourceSha) throw new Error('BLOCKED source branch moved before generated candidate publication.');
    const latestDevelop=call('repos/'+repository+'/git/ref/heads/develop');
    if (latestDevelop?.object?.sha!==request.expectedDevelopSha) throw new Error('BLOCKED develop moved before generated candidate publication.');
    if (autoSquashGenerated) {
        const repositoryInfo=call('repos/'+repository);
        if (repositoryInfo?.full_name!==repository
            || typeof repositoryInfo.node_id!=='string'
            || repositoryInfo.node_id.trim()==='') {
            throw new Error('BLOCKED repository identity is unavailable.');
        }

        const mutationId='prepared:'+String(context.runId)
            +':'+String(context.runAttempt)+':'+commit.sha;

        const result=call('graphql','POST',{
            query:'mutation ($input: UpdateRefsInput!) { updateRefs(input: $input) { clientMutationId } }',
            variables:{
                input:{
                    repositoryId:repositoryInfo.node_id,
                    clientMutationId:mutationId,
                    refUpdates:[{
                        name:'refs/heads/'+branch,
                        beforeOid:sourceSha,
                        afterOid:commit.sha,
                        force:true,
                    }],
                },
            },
        });

        const errors=result?.errors;
        if ((errors!==undefined
                && (!Array.isArray(errors) || errors.length>0))
            || result?.data?.updateRefs?.clientMutationId!==mutationId) {
            throw new Error('BLOCKED conditional feature branch update was not confirmed.');
        }
    } else {
        const written=call('repos/'+repository+'/git/refs/heads/'+encodedBranch(branch),'PATCH',{sha:commit.sha,force:false});
        if (written?.object?.sha!==commit.sha) {
            throw new Error('BLOCKED generated candidate branch update failed.');
        }
    }
    const verified=call('repos/'+repository+'/git/ref/heads/'+encodedBranch(branch));
    if (verified?.object?.sha!==commit.sha) throw new Error('BLOCKED generated candidate branch changed after publication.');
    return {candidateSha:commit.sha,candidateTree:tree.sha,artifactId:request.artifactId,artifactDigest:request.artifactDigest};
}

/**
 * Request BASIC for a verified prepared candidate and return the dispatched run ID.
 * @param {{repository:string,branch:string,candidateSha:string,expectedDevelopSha:string}} request Repository, feature branch, prepared commit and observed develop parent.
 * @param {WorkflowContext} context Exact active main-owned integration workflow context.
 * @param {GithubApi} call Authenticated GitHub API transport.
 * @returns {string} Decimal ID of the newly dispatched BASIC workflow run.
 * @throws {Error} Rejects untrusted context, stale heads, invalid ancestry or authority, failed dispatch or missing run identity.
 */
export function dispatchPreparedBasic(request,context,call) {
    if (!request || !context) {
        throw new Error('BLOCKED prepared BASIC request or context is missing.');
    }

    const {repository,branch,candidateSha,expectedDevelopSha}=request;
    if (!validBranchName(branch) || !branch.startsWith('feature/')
        || context.repository!==repository || context.workflowPath!==WORKFLOW_PATH
        || !trustedWorkflowRef(repository,context.workflowRef,false,context.featureQualification ? context.ref : '')
        || context.eventName!=='workflow_dispatch' || !trustedIntegrationRef(context)
        || context.authorityUpdate
        || ![candidateSha,expectedDevelopSha,context.headSha].every(value=>/^[a-f0-9]{40}$/.test(value))
        || !/^\d+$/.test(String(context.runId))
        || !/^\d+$/.test(String(context.runAttempt))) {
        throw new Error('BLOCKED prepared BASIC dispatch is outside the trusted integration context.');
    }

    const active=call('repos/'+repository+'/actions/runs/'+String(context.runId));
    if (String(active?.id)!==String(context.runId)
        || active.path!==WORKFLOW_PATH || active.event!=='workflow_dispatch'
        || active.head_branch!==integrationBranch(context) || active.head_sha!==context.headSha
        || active.status!=='in_progress'
        || String(active.run_attempt)!==String(context.runAttempt)) {
        throw new Error('BLOCKED prepared BASIC dispatcher is not the active main-owned run.');
    }

    readHeads(call,repository,branch,candidateSha,expectedDevelopSha);
    verifyAncestry(call,repository,candidateSha,candidateSha,expectedDevelopSha,false,false,context.featureQualification);

    const dispatched=call('repos/'+repository+'/actions/workflows/candidate-preparation.yml/dispatches','POST',{
        ref:branch,
        inputs:{maintenance_full:false},
        return_run_details:true,
    });

    if (!Number.isSafeInteger(dispatched?.workflow_run_id)
        || dispatched.workflow_run_id<1) {
        throw new Error('BLOCKED dispatched BASIC run identity was not confirmed.');
    }

    return String(dispatched.workflow_run_id);
}

/**
 * Await the dispatched BASIC run and authenticate the final one-commit selection.
 * @param {{repository:string,branch:string,candidateSha:string,expectedDevelopSha:string,basicRunId:string}} request Exact repository, feature branch, prepared commit, develop parent and dispatched BASIC run ID.
 * @param {WorkflowContext} context Active main-owned integration context authenticated during final selection.
 * @param {GithubApi} call Authenticated GitHub API transport.
 * @param {function(number):Promise<void>} pause Delay implementation; defaults to a timer and accepts the requested milliseconds.
 * @param {function():number} now Monotonic millisecond clock; defaults to performance.now and permits deterministic fixture timing.
 * @returns {Promise<{branch:string,sourceSha:string,expectedDevelopSha:string,sourceBase:string,basicRunId:string,basicRunAttempt:string}>} Authenticated final source and BASIC evidence after successful completion.
 * @throws {Error} Rejects invalid inputs, moved heads, mismatched or unsuccessful runs, superseded evidence, untrusted final selection or timeout.
 */
export async function waitForPreparedBasic(
    request,
    context,
    call,
    pause=milliseconds=>new Promise(resolve=>setTimeout(resolve,milliseconds)),
    now=()=>performance.now()
) {
    if (!request || !context) {
        throw new Error('BLOCKED prepared BASIC wait request or context is missing.');
    }

    const {repository,branch,candidateSha,expectedDevelopSha,basicRunId}=request;
    if (context.repository!==repository || context.authorityUpdate
        || !validBranchName(branch) || !branch.startsWith('feature/')
        || ![candidateSha,expectedDevelopSha].every(value=>/^[a-f0-9]{40}$/.test(value))
        || typeof basicRunId!=='string' || !/^\d+$/.test(basicRunId)
        || !Number.isSafeInteger(Number(basicRunId)) || Number(basicRunId)<1) {
        throw new Error('BLOCKED prepared BASIC wait inputs are invalid.');
    }

    const deadline=now()+PREPARED_BASIC_WAIT_TIMEOUT_MS;
    while (now()<deadline) {
        readHeads(call,repository,branch,candidateSha,expectedDevelopSha);

        const run=call('repos/'+repository+'/actions/runs/'+basicRunId);
        if (String(run?.id)!==basicRunId
            || run.path!==PREPARATION_PATH
            || run.event!=='workflow_dispatch'
            || run.head_branch!==branch
            || run.head_sha!==candidateSha
            || run.run_attempt!==1) {
            throw new Error('BLOCKED prepared BASIC run identity or attempt changed.');
        }

        if (run.status==='completed') {
            if (run.conclusion!=='success') {
                throw new Error('BLOCKED prepared BASIC did not succeed: '+String(run.conclusion));
            }

            const selected=selectDevelopIntegration(branch,context,repository,call);
            if (selected.sourceSha!==candidateSha
                || selected.expectedDevelopSha!==expectedDevelopSha
                || selected.basicRunId!==basicRunId
                || selected.basicRunAttempt!==String(run.run_attempt)) {
                throw new Error('BLOCKED prepared BASIC evidence or selected source was superseded.');
            }

            return selected;
        }

        if (!['queued','in_progress','waiting','pending','requested'].includes(run.status)) {
            throw new Error('BLOCKED prepared BASIC returned an unsupported run status.');
        }

        await pause(PREPARED_BASIC_POLL_INTERVAL_MS);
    }

    throw new Error('BLOCKED timed out waiting for prepared BASIC qualification.');
}
