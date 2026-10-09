/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-owner-authorization.mjs
 * Module Type: Hosted Release Authorization
 * Purpose: Enforce single-owner authorization with distinct GitHub bot PR authorship.
 * Responsibilities:
 *   - Validate main-only owner-reviewed environments and no administrative bypass
 *   - Check installed active branch rulesets against strict reviewed-hosted-CI contracts
 *   - Bind the owner's final pull-request approval to the exact immutable SHA
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/** Authorize only an explicit workflow dispatch by the actual repository owner.
 * @param {string} repository Expected owner/name.
 * @param {function(string):object} call GitHub API reader.
 * @param {Record<string,string|undefined>} context Server workflow context.
 * @returns {string} Verified owner login.
 */
export function requireOwnerDispatch(repository,call,context) {
    const owner=repository.split('/')[0];
    if (!/^[\w.-]+\/[\w.-]+$/.test(repository)
        || context.GITHUB_EVENT_NAME !== 'workflow_dispatch'
        || context.GITHUB_REF !== 'refs/heads/main'
        || context.GITHUB_ACTOR?.toLowerCase() !== owner.toLowerCase()) {
        throw new Error('BLOCKED release write requires explicit owner dispatch from main.');
    }
    const permission=call('repos/'+repository+'/collaborators/'+encodeURIComponent(context.GITHUB_ACTOR)+'/permission');
    if (!['admin','maintain'].includes(permission?.permission)) {
        throw new Error('BLOCKED owner does not have maintainer permission.');
    }
    return owner;
}

/** Validate the exact owner, server review gate, no bypass and main-only deployment.
 * @param {function(string):object} call GitHub API reader.
 * @param {string} repository Expected owner/name.
 * @param {string} name Exact release environment name.
 * @returns {void} Throws unless every server policy is present.
 */
export function requireOwnerEnvironment(call,repository,name) {
    if (!['release-promotion','release-reconciliation','release-retirement'].includes(name)) {
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
        || policy.branch_policies[0].name !== 'main'
        || (policy.branch_policies[0].type !== undefined
            && policy.branch_policies[0].type !== 'branch')) {
        throw new Error('BLOCKED '+name+' must restrict deployments to only main.');
    }
}

/** Require an effective, non-bypassable ruleset with owner review and strict CI.
 * Last-push approval is deliberately disabled: the only human owner may also
 * have pushed the candidate. Stale approvals are dismissed and exact SHA-bound
 * review is independently validated after merge.
 * @param {function(string):object} call GitHub API reader.
 * @param {string} repository Expected owner/name.
 * @param {'main'|'develop'} branch Target protected branch.
 * @param {string} name Installed ruleset name.
 * @param {string} check Exact mandatory status context.
 * @returns {void} Throws on absent, inactive or weakened rules.
 */
export function requireOwnerRuleset(call,repository,branch,name,check) {
    if (!['main','develop'].includes(branch)) throw new Error('BLOCKED unknown protected branch.');
    const prefix='repos/'+repository;
    const all=call(prefix+'/rulesets');
    if (!Array.isArray(all) || all.length >= 100) throw new Error('BLOCKED ruleset listing incomplete.');
    // GitHub may omit target in the ruleset inventory; verify it on the fetched detail.
    const candidates=all.filter(rule=>rule.name===name
        && (rule.target === undefined || rule.target === 'branch'));
    if (candidates.length !== 1 || candidates[0].enforcement !== 'active'
        || !Number.isInteger(candidates[0].id)) {
        throw new Error('BLOCKED '+branch+' required owner ruleset is inactive or ambiguous.');
    }
    const installed=call(prefix+'/rulesets/'+candidates[0].id);
    if (installed?.name !== name || installed.enforcement !== 'active'
        || installed.target !== 'branch' || installed.source !== repository
        || !Array.isArray(installed.bypass_actors) || installed.bypass_actors.length !== 0
        || (installed.current_user_can_bypass !== undefined && installed.current_user_can_bypass !== 'never')
        || JSON.stringify(installed.conditions?.ref_name?.include) !== JSON.stringify(['refs/heads/'+branch])
        || JSON.stringify(installed.conditions?.ref_name?.exclude) !== '[]') {
        throw new Error('BLOCKED '+branch+' ruleset permits bypass or targets unexpected refs.');
    }
    for (const rules of [installed.rules,call(prefix+'/rules/branches/'+branch)]) {
        if (!Array.isArray(rules)) throw new Error('BLOCKED effective rules are unreadable.');
        const review=rules.find(rule=>rule.type==='pull_request')?.parameters;
        const ci=rules.find(rule=>rule.type==='required_status_checks')?.parameters;
        if (!rules.some(rule=>rule.type==='deletion') || !rules.some(rule=>rule.type==='non_fast_forward')
            || review?.required_approving_review_count !== 1
            || review.dismiss_stale_reviews_on_push !== true
            || review.require_last_push_approval !== false
            || review.required_review_thread_resolution !== true
            || JSON.stringify(review.allowed_merge_methods) !== '["merge"]'
            || ci?.strict_required_status_checks_policy !== true
            || !Array.isArray(ci.required_status_checks)
            || !ci.required_status_checks.some(required=>required.context===check)) {
            throw new Error('BLOCKED '+branch+' lacks strict manual PR approval and hosted CI protections.');
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
        || pr.base?.ref !== target || !Number.isInteger(pr.number) || pr.number < 1) {
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
