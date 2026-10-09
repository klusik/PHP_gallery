/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-owner-authorization.mjs
 * Module Type: Hosted Release Authorization
 * Purpose: Enforce single-owner authorization with distinct GitHub bot PR authorship.
 * Responsibilities:
 *   - Validate owner-reviewed environments on main or the pinned publication recovery, without bypass
 *   - Check installed active branch rulesets against branch-specific reviewed/linear hosted-CI contracts
 *   - Bind the owner's final pull-request approval to the exact immutable SHA
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

import {createHash} from 'node:crypto';

/** @typedef {null|boolean|number|string|JsonValue[]|{[key:string]:JsonValue}} JsonValue */

/** Explicit publication inputs admitted by the one-release recovery exception.
 * @typedef {{runId:string,candidate:string,branch:string,mode:string,override:boolean,reason:string,acceptedFailures:string[],manualReview:string}} RecoveryRequest
 */

// Pin the only owner-authorized off-main publisher to the already merged release.
// Type: Readonly release identity record. Units: Git/GitHub identities.
// Scope: v_0.126 publication only. Consumers: dispatch, environment and publication guards.
// Rationale: main must remain M; no other branch or release may obtain this exception.
export const PUBLICATION_RECOVERY = Object.freeze({
    repository:'klusik/PHP_gallery',owner:'klusik',branch:'hotfix/v_0.126-publication-recovery',
    ref:'refs/heads/hotfix/v_0.126-publication-recovery',environment:'release-publication-recovery-v_0.126',
    tag:'v_0.126',version:'0.126',releaseBranch:'release/v_0.126',runId:'38000825301',attempt:'1',
    candidate:'aef94a6688c7a437abfed3cc4bc2c29a283421bf',
    main:'e0637b0e1e7aa574f7495ed8cfa92a149f1e73d3',
    predecessor:'9ef4fbe54ecb4f2eaeba7d6595cabda016b50fc3',
    develop:'44ba56cca2f110a2aa2527da337014750aebc6a3',
    tree:'8dc3aa0eada8ec63343b5924543a2e867bd87383',pr:179,reviewId:5476274538,
});

/** Admit only the owner-dispatched publication of the pinned release from its recovery branch.
 * @param {string} repository Expected owner/name.
 * @param {Record<string,string|undefined>} context Server workflow context, including ref, actor and tooling SHA.
 * @param {RecoveryRequest|null} request Exact publication inputs; null never authorizes recovery.
 * @param {boolean} readOnly Whether an owner push may model a future dispatch for a transport that prohibits every server write.
 * @returns {boolean} True for the exact recovery identity, false for other refs; throws for invalid recovery inputs.
 */
export function requirePublicationRecoveryDispatch(repository,context,request,readOnly=false) {
    const pinned=PUBLICATION_RECOVERY;
    if (context.GITHUB_REF !== pinned.ref) return false;
    if (repository !== pinned.repository || (context.GITHUB_EVENT_NAME !== 'workflow_dispatch'
        && !(readOnly && context.GITHUB_EVENT_NAME==='push'))
        || context.GITHUB_ACTOR !== pinned.owner
        || (context.GITHUB_TRIGGERING_ACTOR !== undefined && context.GITHUB_TRIGGERING_ACTOR !== pinned.owner)
        || !/^[a-f0-9]{40}$/.test(context.GITHUB_SHA ?? '')
        || request?.mode !== 'publish' || request.branch !== pinned.releaseBranch
        || request.candidate !== pinned.candidate || request.runId !== pinned.runId
        || request.override !== false || request.reason !== '' || request.acceptedFailures.length !== 0
        || !request.manualReview.trim()) {
        throw new Error('BLOCKED recovery permits only owner-dispatched v_0.126 publication at the pinned identities.');
    }
    return true;
}

/** Decode a qualification check's explicit run/attempt/SHA binding independently of GitHub's rewritten URL.
 * @param {string|null|undefined} externalId Server-retained external_id from the Actions check.
 * @returns {{kind:string,runId:string,attempt:number,sha:string}|null} Complete qualification identity, or null for absent/malformed binding.
 */
export function qualificationCheckBinding(externalId) {
    const match=/^(candidate|release):([1-9]\d*):([1-9]\d*):([a-f0-9]{40})$/.exec(externalId ?? '');
    if (!match || !Number.isSafeInteger(Number(match[3]))) return null;
    return {kind:match[1],runId:match[2],attempt:Number(match[3]),sha:match[4]};
}

/** Authorize only an explicit owner dispatch from main or the pinned publication recovery.
 * @param {string} repository Expected owner/name.
 * @param {function(string):JsonValue} call GitHub API reader returning server permission fields.
 * @param {Record<string,string|undefined>} context Server workflow context.
 * @param {RecoveryRequest|null} request Exact recovery inputs, or null for the standard main-only contract.
 * @param {boolean} readOnly Whether to validate an owner push as read-only recovery evidence, never write authority.
 * @returns {string} Verified owner login.
 */
export function requireOwnerDispatch(repository,call,context,request=null,readOnly=false) {
    const owner=repository.split('/')[0];
    const recovery=requirePublicationRecoveryDispatch(repository,context,request,readOnly);
    if (!/^[\w.-]+\/[\w.-]+$/.test(repository)
        || (context.GITHUB_EVENT_NAME !== 'workflow_dispatch' && !(readOnly && recovery))
        || (!recovery && context.GITHUB_REF !== 'refs/heads/main')
        || context.GITHUB_ACTOR?.toLowerCase() !== owner.toLowerCase()) {
        throw new Error('BLOCKED release write requires explicit owner dispatch from main or pinned publication recovery.');
    }
    const permission=call('repos/'+repository+'/collaborators/'+encodeURIComponent(context.GITHUB_ACTOR)+'/permission');
    if (!['admin','maintain'].includes(permission?.permission)) {
        throw new Error('BLOCKED owner does not have maintainer permission.');
    }
    return owner;
}

/** Validate the owner review gate, no bypass and the single permitted deployment branch.
 * @param {function(string):JsonValue} call GitHub API reader returning environment protection and branch-policy fields.
 * @param {string} repository Expected owner/name.
 * @param {string} name Exact release environment name.
 * @param {Record<string,string|undefined>|null} context Server context required only for the recovery environment.
 * @param {RecoveryRequest|null} request Exact publication request required only for the recovery environment.
 * @param {boolean} readOnly Whether an owner push is evidence-only and cannot authorize deployment.
 * @returns {void} Throws unless every server policy is present.
 */
export function requireOwnerEnvironment(call,repository,name,context=null,request=null,readOnly=false) {
    const recovery=name===PUBLICATION_RECOVERY.environment;
    if (recovery && (!context || !requirePublicationRecoveryDispatch(repository,context,request,readOnly))) {
        throw new Error('BLOCKED recovery environment requires the exact authorized publication dispatch.');
    }
    if (!recovery && !['release-promotion','release-reconciliation','release-retirement'].includes(name)) {
        throw new Error('BLOCKED unknown release environment.');
    }
    const owner=repository.split('/')[0].toLowerCase();
    const environment=call('repos/'+repository+'/environments/'+name);
    const reviews=environment?.protection_rules?.filter(rule=>rule.type==='required_reviewers');
    if (reviews?.length !== 1 || reviews[0].prevent_self_review !== false
        || reviews[0].reviewers?.length !== 1 || reviews[0].reviewers[0]?.type !== 'User'
        || reviews[0].reviewers[0]?.reviewer?.login?.toLowerCase() !== owner
        || environment.can_admins_bypass !== false
        || environment.deployment_branch_policy?.custom_branch_policies !== true
        || environment.deployment_branch_policy?.protected_branches !== false) {
        throw new Error('BLOCKED '+name+' must require the sole owner, permit self-review and forbid admin bypass.');
    }
    const policy=call('repos/'+repository+'/environments/'+name+'/deployment-branch-policies?per_page=100');
    if (policy?.total_count !== 1 || policy.branch_policies?.length !== 1
        || policy.branch_policies[0].name !== (recovery ? PUBLICATION_RECOVERY.branch : 'main')
        || (policy.branch_policies[0].type !== undefined
            && policy.branch_policies[0].type !== 'branch')) {
        throw new Error('BLOCKED '+name+' must restrict deployments to its single authorized branch.');
    }
}

/** Serialize complete JSON evidence with stable object key ordering and ordered arrays.
 * @param {JsonValue} value Complete server policy or attestation data.
 * @returns {string} Canonical JSON bytes used for policy comparison and SHA-256.
 */
export function canonicalPolicy(value) {
    if (Array.isArray(value)) return '['+value.map(item=>canonicalPolicy(item)).join(',')+']';
    if (value && typeof value==='object') return '{'+Object.keys(value).sort()
        .map(key=>JSON.stringify(key)+':'+canonicalPolicy(value[key])).join(',')+'}';
    return JSON.stringify(value);
}

/** Verify an explicit human security-contract approval against the current redacted server policy.
 * @param {function(string):JsonValue} call Read-only server API, including the owner comment.
 * @param {string} repository Exact recovery repository.
 * @param {JsonValue} installed Current complete token-visible ruleset detail.
 * @param {{context:Record<string,string|undefined>,request:RecoveryRequest,commentId:string,readOnly?:boolean}|null} proof Exact owner approval reference and dispatch identities; null never supplies a hidden bypass inventory.
 * @returns {JsonValue} Full owner-attested policy, or throws on missing approval, drift or conflicting evidence.
 */
export function verifyRulesetAttestation(call,repository,installed,proof) {
    if (!proof || !/^[1-9]\d*$/.test(proof.commentId ?? '')) {
        throw new Error('BLOCKED_RULESET_ATTESTATION_REQUIRED: bypass_actors is omitted; ruleset write access or explicit owner security-contract approval is required.');
    }
    if (!requirePublicationRecoveryDispatch(repository,proof.context,proof.request,proof.readOnly===true)) {
        throw new Error('BLOCKED_RULESET_ATTESTATION_SCOPE: only the exact recovery dispatch admits attestation.');
    }
    const comment=call('repos/'+repository+'/issues/comments/'+proof.commentId);
    if (String(comment?.id)!==proof.commentId || comment.user?.login!==PUBLICATION_RECOVERY.owner
        || comment.user?.type!=='User' || comment.author_association!=='OWNER'
        || comment.issue_url!=='https://api.github.com/repos/'+repository+'/issues/133'
        || !Number.isFinite(Date.parse(comment.created_at)) || comment.created_at!==comment.updated_at) {
        throw new Error('BLOCKED_RULESET_ATTESTATION_OWNER: approval must be an unedited server-owned owner comment on #133.');
    }
    let approval;
    try { approval=JSON.parse(comment.body); }
    catch { throw new Error('BLOCKED_RULESET_ATTESTATION_FORMAT: owner comment must contain only complete JSON.'); }
    const pinned=PUBLICATION_RECOVERY;
    if (!approval || typeof approval!=='object' || Array.isArray(approval)) {
        throw new Error('BLOCKED_RULESET_ATTESTATION_FORMAT: approval must be a JSON object.');
    }
    if (approval.schema_version!==1 || approval.decision!=='APPROVE_RULESET_ATTESTATION_SECURITY_CONTRACT_V1'
        || approval.repository!==repository || approval.ruleset_id!==24808772
        || approval.qualification_sha!==pinned.candidate || approval.main_sha!==pinned.main
        || approval.recovery_ref!==pinned.ref || approval.tooling_sha!==proof.context.GITHUB_SHA
        || approval.qualification_run!==pinned.runId || approval.qualification_attempt!==pinned.attempt
        || approval.environment!==pinned.environment || approval.owner!==pinned.owner) {
        throw new Error('BLOCKED_RULESET_ATTESTATION_BINDING: security decision, Q/M, tooling, ref or original qualification differs.');
    }
    const policy=approval.policy;
    if (!policy || policy.id!==24808772 || typeof policy.node_id!=='string' || !policy.node_id
        || policy.source_type!=='Repository' || policy.source!==repository
        || !Number.isFinite(Date.parse(policy.created_at)) || !Number.isFinite(Date.parse(policy.updated_at))
        || !Array.isArray(policy.bypass_actors) || policy.bypass_actors.length!==0
        || policy.current_user_can_bypass!=='never' || Date.parse(policy.updated_at)>Date.parse(comment.created_at)
        || approval.policy_sha256!==createHash('sha256').update(canonicalPolicy(policy)).digest('hex')) {
        throw new Error('BLOCKED_RULESET_ATTESTATION_POLICY: complete owner-visible policy with zero bypass actors and digest is required.');
    }
    // Only the documented redaction and caller-specific field may differ. Extra/missing fields fail closed.
    const visible={...installed};const attested={...policy};
    delete visible.bypass_actors;delete attested.bypass_actors;
    delete visible.current_user_can_bypass;delete attested.current_user_can_bypass;
    // The same server instant is returned as UTC to installation tokens and an offset to the owner.
    for (const field of ['created_at','updated_at']) {
        if (!Number.isFinite(Date.parse(visible[field])) || Date.parse(visible[field])!==Date.parse(attested[field])) {
            throw new Error('BLOCKED_RULESET_ATTESTATION_DRIFT: current server timestamp differs.');
        }
        visible[field]=new Date(visible[field]).toISOString();
        attested[field]=new Date(attested[field]).toISOString();
    }
    if (installed.current_user_can_bypass!=='never' || canonicalPolicy(visible)!==canonicalPolicy(attested)) {
        throw new Error('BLOCKED_RULESET_ATTESTATION_DRIFT: current identity, updated_at or complete visible policy differs.');
    }
    if (Object.hasOwn(installed,'bypass_actors') && canonicalPolicy(installed.bypass_actors)!=='[]') {
        throw new Error('BLOCKED_RULESET_ATTESTATION_CONFLICT: live bypass inventory conflicts with approval.');
    }
    return policy;
}

/** Require branch-specific non-bypassable policies: reviewed main merges or true linear develop FF.
 * Last-push approval is deliberately disabled: the only human owner may also
 * have pushed the candidate. Stale approvals are dismissed and exact SHA-bound
 * review is independently validated after merge.
 * @param {function(string):JsonValue} call GitHub API reader.
 * @param {string} repository Expected owner/name.
 * @param {'main'|'develop'} branch Target protected branch.
 * @param {string} name Installed ruleset name.
 * @param {string} check Exact mandatory status context.
 * @param {{context:Record<string,string|undefined>,request:RecoveryRequest,commentId:string,readOnly?:boolean}|null} proof Optional explicit security-contract approval; never assumed for redacted responses.
 * @returns {void} Throws with a distinct code on absent, inactive, redacted, drifting or weakened rules.
 */
export function requireOwnerRuleset(call,repository,branch,name,check,proof=null) {
    if (!['main','develop'].includes(branch)) throw new Error('BLOCKED unknown protected branch.');
    const prefix='repos/'+repository;
    const all=call(prefix+'/rulesets?per_page=100');
    if (!Array.isArray(all) || all.length >= 100) throw new Error('BLOCKED_RULESET_INVENTORY: ruleset listing incomplete.');
    // GitHub may omit target in the ruleset inventory; verify it on the fetched detail.
    const candidates=all.filter(rule=>rule.name===name
        && (rule.target === undefined || rule.target === 'branch'));
    if (candidates.length !== 1 || candidates[0].enforcement !== 'active'
        || !Number.isInteger(candidates[0].id)) {
        throw new Error('BLOCKED_RULESET_SELECTION: '+branch+' owner ruleset is inactive or ambiguous.');
    }
    const installed=call(prefix+'/rulesets/'+candidates[0].id);
    if (installed?.id!==candidates[0].id || installed.name!==name || installed.source!==repository
        || installed.target!=='branch') throw new Error('BLOCKED_RULESET_IDENTITY: detail identity or target differs.');
    if (proof && installed.id!==24808772) throw new Error('BLOCKED_RULESET_IDENTITY: recovery ruleset ID differs.');
    if (installed.enforcement!=='active') throw new Error('BLOCKED_RULESET_ENFORCEMENT: ruleset is not active.');
    if (JSON.stringify(installed.conditions?.ref_name?.include)!==JSON.stringify(['refs/heads/'+branch])
        || JSON.stringify(installed.conditions?.ref_name?.exclude)!=='[]') {
        throw new Error('BLOCKED_RULESET_REFS: unexpected protected refs.');
    }
    if (installed.current_user_can_bypass!==undefined && installed.current_user_can_bypass!=='never') {
        throw new Error('BLOCKED_RULESET_TOKEN_BYPASS: caller can bypass the ruleset.');
    }
    if (Object.hasOwn(installed,'bypass_actors') && !Array.isArray(installed.bypass_actors)) {
        throw new Error('BLOCKED_RULESET_BYPASS_TYPE: bypass_actors must be an explicit array.');
    }
    if (Array.isArray(installed.bypass_actors) && installed.bypass_actors.length!==0) {
        throw new Error('BLOCKED_RULESET_BYPASS_ACTORS: ruleset has bypass actors.');
    }
    if (!Object.hasOwn(installed,'bypass_actors') || proof?.commentId) {
        if (branch!=='main') throw new Error('BLOCKED_RULESET_BYPASS_UNREADABLE: develop requires a live complete bypass inventory.');
        verifyRulesetAttestation(call,repository,installed,proof);
    }
    verifyRulesetEffectivePolicy(installed,call(prefix+'/rules/branches/'+branch),repository,branch,check);
}

/** Verify the complete installed/effective policy and source binding independently of bypass visibility.
 * @param {JsonValue} installed Current token-visible installed Ruleset detail.
 * @param {JsonValue} effective Current effective branch rules returned by the server.
 * @param {string} repository Exact repository identity.
 * @param {'main'|'develop'} branch Protected branch whose rules must match.
 * @param {string} check Required GitHub Actions status context.
 * @returns {void} Throws on unreadable, overlapping, conflicting or incompatible effective policies.
 */
export function verifyRulesetEffectivePolicy(installed,effective,repository,branch,check) {
    if (!Array.isArray(effective)) throw new Error('BLOCKED_RULESET_EFFECTIVE_UNREADABLE: effective rules are unreadable.');
    const normalized=rules=>rules.map(rule=>({type:rule.type,...(rule.parameters===undefined ? {} : {parameters:rule.parameters})}))
        .sort((left,right)=>left.type.localeCompare(right.type));
    if (!Array.isArray(installed.rules) || canonicalPolicy(normalized(installed.rules))!==canonicalPolicy(normalized(effective))
        || effective.some(rule=>(rule.ruleset_id!==undefined && rule.ruleset_id!==installed.id)
            || (rule.ruleset_source!==undefined && rule.ruleset_source!==repository)
            || (rule.ruleset_source_type!==undefined && rule.ruleset_source_type!=='Repository'))) {
        throw new Error('BLOCKED_RULESET_EFFECTIVE_CONFLICT: effective policies or their provenance differ from the installed ruleset.');
    }
    for (const rules of [installed.rules,effective]) {
        if (!Array.isArray(rules)) throw new Error('BLOCKED_RULESET_RULES_TYPE: rules are unreadable.');
        const allowed = ['deletion','non_fast_forward','required_status_checks',
            branch === 'main' ? 'pull_request' : 'required_linear_history'];
        if (rules.length !== allowed.length || allowed.some(type=>rules.filter(rule=>rule.type===type).length!==1)) {
            throw new Error('BLOCKED_RULESET_RULES_INVENTORY: unknown or overlapping effective policies require review.');
        }
        const review=rules.find(rule=>rule.type==='pull_request')?.parameters;
        const ci=rules.find(rule=>rule.type==='required_status_checks')?.parameters;
        const common = rules.some(rule=>rule.type==='deletion')
            && rules.some(rule=>rule.type==='non_fast_forward')
            && Array.isArray(ci?.required_status_checks)
            && ci.do_not_enforce_on_create === false
            && ci.required_status_checks.some(required=>required.context===check && required.integration_id === 15368);
        const mainPolicy = review?.required_approving_review_count === 1
            && review.dismiss_stale_reviews_on_push === true
            && review.require_last_push_approval === false
            && review.required_review_thread_resolution === true
            && JSON.stringify(review.allowed_merge_methods) === '["merge"]'
            && ci?.strict_required_status_checks_policy === false
            && !rules.some(rule=>rule.type==='required_linear_history');
        const developPolicy = !rules.some(rule=>rule.type==='pull_request')
            && rules.some(rule=>rule.type==='required_linear_history')
            && ci?.strict_required_status_checks_policy === true;
        if (!common || !(branch === 'main' ? mainPolicy : developPolicy)) {
            throw new Error('BLOCKED_RULESET_POLICY: '+branch+' lacks compatible owner/linear history and exact hosted CI protections.');
        }
    }
}

/** Reject any PR not authored by the workflow bot for the exact source SHA.
 * @param {{number:number,user:{login:string},head:{sha:string,repo:{full_name:string}},base:{ref:string},merged_at?:string|null,created_at?:string,merge_commit_sha?:string,html_url?:string}} pr Complete GitHub pull-request REST object.
 * @param {string} repository Expected owner/name.
 * @param {string} sha Exact candidate source.
 * @param {'main'|'develop'} target Intended protected branch.
 * @returns {void} Throws on forged, stale or owner-authored PR.
 */
export function requireBotPullRequest(pr,repository,sha,target) {
    if (pr?.user?.login?.toLowerCase() !== 'github-actions[bot]'
        || pr.head?.sha !== sha || pr.head?.repo?.full_name !== repository
        || pr.base?.ref !== target || pr.auto_merge != null || !Number.isInteger(pr.number) || pr.number < 1) {
        throw new Error('BLOCKED exact PR must be authored by github-actions[bot], not the human reviewer.');
    }
}

/** Select only the final effective human owner's SHA-bound approval before merge.
 * @param {{id:number,user:{login:string},state:string,commit_id:string,submitted_at:string,html_url?:string}[]} reviews Complete GitHub PR review listing, without truncation.
 * @param {{user:{login:string},merged_at:string|null,created_at?:string}} pr Merged GitHub PR containing server timestamps and author.
 * @param {string} owner Sole human approver.
 * @param {string} sha Exact approved head SHA.
 * @returns {{id:number,reviewer:string,commit_sha:string,submitted_at:string,url:string|null}|null} Audit-ready review or null if it cannot be proven.
 */
export function effectiveOwnerReview(reviews,pr,owner,sha) {
    const merged=Date.parse(pr?.merged_at ?? '');
    if (!Array.isArray(reviews) || reviews.length >= 100 || !Number.isFinite(merged)
        || pr?.user?.login?.toLowerCase() === owner.toLowerCase()) return null;
    let latest=null;
    for (const review of reviews) {
        if (review.user?.login?.toLowerCase() !== owner.toLowerCase()
            || !['APPROVED','CHANGES_REQUESTED','DISMISSED'].includes(review.state)) continue;
        const when=Date.parse(review.submitted_at ?? '');
        if (!Number.isFinite(when) || when > merged
            || (pr.created_at && when < Date.parse(pr.created_at))) continue;
        if (!latest || when >= latest.when) latest={review,when};
    }
    if (latest?.review?.state !== 'APPROVED' || latest.review.commit_id !== sha
        || !Number.isInteger(latest.review.id) || latest.review.id < 1) return null;
    return {id:latest.review.id,reviewer:owner,commit_sha:sha,
        submitted_at:latest.review.submitted_at,url:latest.review.html_url ?? null};
}

/** Authorize a token-created qualification only through a completed owner initialization run.
 * @param {function(string):JsonValue} call Read-only GitHub API.
 * @param {string} repository Exact repository.
 * @param {string} runId Parent Start New Release run ID.
 * @param {{branch:string,origin_sha:string,selected_develop_sha:string,owner:string,run_id:string,run_attempt:string}} record Downloaded complete initialization artifact.
 * @param {string} branch Actual qualification branch.
 * @param {string} originSha Git-proven origin introduction commit.
 * @returns {void} Throws unless the exact origin was created by the successfully approved owner workflow.
 */
export function requireInitializationHandoff(call,repository,runId,record,branch,originSha) {
    if (!/^[1-9]\d*$/.test(runId) || record.run_id!==runId || record.branch!==branch
        || record.origin_sha!==originSha || record.owner!==repository.split('/')[0]
        || !/^[1-9]\d*$/.test(record.run_attempt)) throw new Error('BLOCKED unbound initialization handoff.');
    const run=call('repos/'+repository+'/actions/runs/'+runId+'/attempts/'+record.run_attempt);
    if (run.path!=='.github/workflows/start-new-release.yml' || run.event!=='workflow_dispatch'
        || run.head_branch!=='main' || run.actor?.login!==record.owner
        || run.run_attempt!==Number(record.run_attempt) || run.status!=='completed' || run.conclusion!=='success') {
        throw new Error('BLOCKED initialization is not a completed owner-approved main workflow.');
    }
}
