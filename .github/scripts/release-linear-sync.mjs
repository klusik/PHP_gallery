/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-linear-sync.mjs
 * Module Type: Reviewed Release History Synchronization
 * Purpose: Retain reviewed release ancestry by fast-forwarding develop to actual main.
 * Responsibilities:
 *   - Keep prepared release bytes unchanged when joining the frozen main history
 *   - Publish only the actual reviewed main commit above an unchanged qualified develop source
 *   - Bind the normal protected update to real assembly evidence without another functional audit
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {publishIntegrationStatus,requireIntegrationStatus} from './develop-integration.mjs';
import {isAncestor} from './release-reconciliation.mjs';

/** Link the frozen main history without changing the prepared release contents, before human review.
 * @param {string} candidate Complete locally prepared release commit.
 * @param {string} main Exact main frozen by the immutable release origin.
 * @param {import('./release-completion.mjs').CompletionCommand} git Literal Git adapter in the release checkout.
 * @returns {string} Existing candidate when main is retained already, otherwise a two-parent commit with the identical tree; invalid identities throw.
 */
export function linkReleaseHistory(candidate,main,git) {
    if (![candidate,main].every(value=>/^[a-f0-9]{40}$/.test(value))) throw new Error('BLOCKED invalid release history identities.');
    if (git('git',['merge-base',main,candidate])===main) return candidate;
    const tree=git('git',['rev-parse',candidate+'^{tree}']);
    return git('git',['commit-tree',tree,'-p',candidate,'-p',main,'-m',
        'Link observed main before release review; preserve prepared contents']);
}

/** Fast-forward unchanged develop to the actual owner-approved schema-4 release merge.
 * @param {import('./release-completion.mjs').CompletionRecord} record Immutable successful assembly record, validated by completeRelease before this call.
 * @param {string} repository Exact owner/repository.
 * @param {string} main Verified final merge/tag commit.
 * @param {import('./release-completion.mjs').CompletionApi} call Actions API adapter with normal protected-ref and status publication permissions.
 * @returns {{state:string,sha:string,detail:string}} Actual main SHA or a concurrent-develop blocker; invalid ancestry, provider evidence or rejected writes throw. Never creates a commit or forces a ref.
 */
export function synchronizeReleaseTree(record,repository,main,call) {
    const endpoint='repos/'+repository;
    const candidate=call(endpoint+'/git/commits/'+record.candidate_sha);
    const tree=candidate.tree?.sha;
    const released=call(endpoint+'/git/commits/'+main);
    if (candidate.sha!==record.candidate_sha || !/^[a-f0-9]{40}$/.test(tree ?? '')
        || released.sha!==main || released.tree?.sha!==tree
        || released.parents?.length!==2
        || released.parents[0]?.sha!==record.initial_main_sha
        || released.parents[1]?.sha!==record.candidate_sha) {
        throw new Error('BLOCKED main is not the exact reviewed release merge and tree.');
    }
    const develop=call(endpoint+'/git/ref/heads/develop').object?.sha;
    const current=call(endpoint+'/git/commits/'+develop);
    if (current.sha!==develop) throw new Error('BLOCKED current develop identity is unavailable.');
    if (!isAncestor(call,repository,record.selected_develop_sha,main)) {
        throw new Error('BLOCKED selected develop is absent from the reviewed main history.');
    }
    if (develop!==main && develop!==record.selected_develop_sha) {
        return {state:'BLOCKED_DEVELOP_ADVANCED',sha:develop,detail:'Develop advanced after the selected release source; no content was overwritten.'};
    }
    if (call(endpoint+'/git/ref/heads/develop').object?.sha!==develop
        || call(endpoint+'/git/ref/heads/main').object?.sha!==main
        || call(endpoint+'/git/ref/heads/'+record.branch).object?.sha!==record.candidate_sha) {
        throw new Error('BLOCKED release synchronization refs changed before publication.');
    }
    if (develop===main) {
        return {state:'RECONCILED_FF',sha:main,detail:'Protected develop already points to the actual reviewed main release commit.'};
    }
    // Bind real assembly evidence to M, preserving its qualified source ancestry.
    const status=publishIntegrationStatus(repository,main,record.run_id,call,
        'Reviewed release assembly; actual main commit and qualified source ancestry.');
    requireIntegrationStatus(repository,main,status,call);
    // Status publication is another provider boundary: lease all refs again.
    if (call(endpoint+'/git/ref/heads/develop').object?.sha!==develop
        || call(endpoint+'/git/ref/heads/main').object?.sha!==main
        || call(endpoint+'/git/ref/heads/'+record.branch).object?.sha!==record.candidate_sha) {
        throw new Error('BLOCKED release synchronization refs changed after status publication.');
    }
    const updated=call(endpoint+'/git/refs/heads/develop','PATCH',{sha:main,force:false});
    if (updated?.object?.sha!==main
        || call(endpoint+'/git/ref/heads/develop').object?.sha!==main) {
        throw new Error('BLOCKED protected main-to-develop fast-forward was not confirmed.');
    }
    return {state:'RECONCILED_FF',sha:main,
        detail:'Develop points to the actual reviewed main release commit; no synchronization commit was created.'};
}
