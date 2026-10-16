/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-linear-sync.mjs
 * Module Type: Reviewed Release History Synchronization
 * Purpose: Link the observed main before review and project the approved release tree onto linear develop.
 * Responsibilities:
 *   - Keep prepared release bytes unchanged when joining the frozen main history
 *   - Publish only an exact approved release tree above an unchanged qualified develop source
 *   - Bind the normal protected update to real assembly evidence without another functional audit
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {publishIntegrationStatus,requireIntegrationStatus} from './develop-integration.mjs';

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

/** Synchronize an already verified, owner-approved and tagged schema-4 release without merging into develop.
 * @param {import('./release-completion.mjs').CompletionRecord} record Immutable successful assembly record, validated by completeRelease before this call.
 * @param {string} repository Exact owner/repository.
 * @param {string} main Verified final merge/tag commit.
 * @param {import('./release-completion.mjs').CompletionApi} call Actions API adapter with normal protected-ref and status publication permissions.
 * @returns {{state:string,sha:string,detail:string}} Exact linear result or an explicit concurrent-develop blocker; invalid provider evidence or rejected writes throw.
 */
export function synchronizeReleaseTree(record,repository,main,call) {
    const endpoint='repos/'+repository;
    const candidate=call(endpoint+'/git/commits/'+record.candidate_sha);
    const tree=candidate.tree?.sha;
    const develop=call(endpoint+'/git/ref/heads/develop').object?.sha;
    const current=call(endpoint+'/git/commits/'+develop);
    if (!/^[a-f0-9]{40}$/.test(tree ?? '') || current.sha!==develop) throw new Error('BLOCKED release synchronization tree is unavailable.');
    if (develop===record.candidate_sha || current.tree?.sha===tree) {
        return {state:'RECONCILED_EQUIVALENT',sha:develop,detail:'Protected develop already retains the complete reviewed release tree.'};
    }
    if (develop!==record.selected_develop_sha) {
        return {state:'BLOCKED_DEVELOP_ADVANCED',sha:develop,detail:'Develop advanced after the selected release source; no content was overwritten.'};
    }
    const result=call(endpoint+'/git/commits','POST',{tree,parents:[develop],
        message:'Synchronize reviewed release v_'+record.version+' into develop\n\n'
            +'Release-Candidate: '+record.candidate_sha+'\nRelease-Main: '+main+'\nSource-Develop: '+develop});
    if (!/^[a-f0-9]{40}$/.test(result.sha ?? '') || result.parents?.length!==1
        || result.parents[0]?.sha!==develop || result.tree?.sha!==tree) {
        throw new Error('BLOCKED synchronization commit does not retain the exact release tree and linear parent.');
    }
    // This status represents the already verified assembly and exact tree, never a fictional FULL on this new SHA.
    const status=publishIntegrationStatus(repository,result.sha,record.run_id,call,
        'Reviewed release assembly; identical tree and qualified develop source.');
    requireIntegrationStatus(repository,result.sha,status,call);
    if (call(endpoint+'/git/ref/heads/develop').object?.sha!==develop
        || call(endpoint+'/git/ref/heads/main').object?.sha!==main
        || call(endpoint+'/git/ref/heads/'+record.branch).object?.sha!==record.candidate_sha) {
        throw new Error('BLOCKED release synchronization refs changed before publication.');
    }
    const updated=call(endpoint+'/git/refs/heads/develop','PATCH',{sha:result.sha,force:false});
    if (updated?.object?.sha!==result.sha) throw new Error('BLOCKED protected synchronization was not confirmed.');
    return {state:'RECONCILED_EQUIVALENT',sha:result.sha,
        detail:'Exact reviewed release tree, one parent above selected develop; original source qualification and assembly retained.'};
}
