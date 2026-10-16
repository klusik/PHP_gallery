/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/release_linear_sync_test.mjs
 * Module Type: Release Synchronization Regression Test
 * Purpose: Require develop to retain the actual reviewed main commit and historical receipts.
 * Responsibilities:
 *   - Use real disposable Git objects, normal pushes and realistic Actions status responses
 *   - Prove idempotence, next-release initialization and refusal of concurrent or altered inputs
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {linkReleaseHistory,synchronizeReleaseTree} from '../.github/scripts/release-linear-sync.mjs';
import {startNewRelease} from '../.github/scripts/start-new-release.mjs';
import {inspectSync} from '../.github/scripts/release-reconciliation.mjs';
import {requireMainFollowingDevelop,effectiveOwnerReview} from '../.github/scripts/release-owner-authorization.mjs';
import {inspectReadiness} from '../.github/scripts/release-readiness.mjs';
import {lifecycleFixture} from './support/release_lifecycle_fixture.mjs';

for (const scenario of ['normal','advanced','equivalent-sibling','parent','tree',
    'ancestry','creator','missing-status','develop-race','main-race','head-race',
    'status-race','readback']) {
    const f=lifecycleFixture();
    try {
        const initialized=startNewRelease({version:'1.2.1',selected:f.selected},f.repository,f);
        const record=f.prepare(initialized);
        const linked=linkReleaseHistory(record.candidate_sha,f.previousMain,f.git);
        const tree=f.git('git',['rev-parse',record.candidate_sha+'^{tree}']);
        assert.equal(f.git('git',['rev-parse',linked+'^{tree}']),tree);
        assert.equal(f.git('git',['rev-parse',linked+'^1']),record.candidate_sha);
        assert.equal(f.git('git',['rev-parse',linked+'^2']),f.previousMain);
        assert.equal(linkReleaseHistory(linked,f.previousMain,f.git),linked,'history linking is idempotent');
        f.git('git',['push','origin',linked+':refs/heads/'+record.branch]);
        record.candidate_sha=linked;record.lifecycle_schema=4;
        const pr=f.api('repos/'+f.repository+'/pulls','POST',{});
        const main=f.git('git',['commit-tree',tree,'-p',f.previousMain,'-p',linked,'-m','Reviewed release merge']);
        f.git('git',['push','origin',main+':refs/heads/main']);
        const statuses=new Map(),writes=[];
        let developReads=0;
        let unreadableWrite=false;
        const call=(path,method='GET',payload=null)=>{
            if (method!=='GET') writes.push({path,method,payload});
            if (path.includes('/statuses/') && method==='POST') {
                const sha=path.split('/').at(-1);
                const status={...payload,id:1000+statuses.size,creator:{login:scenario==='creator'?'intruder':'github-actions[bot]'}};
                statuses.set(sha,status);return status;
            }
            if (path.endsWith('/status?per_page=100&page=1')) {
                const status=statuses.get(path.split('/commits/')[1].split('/')[0]);
                const {creator,...combined}=status;
                return {total_count:scenario==='missing-status'?0:1,statuses:scenario==='missing-status'?[]:[combined]};
            }
            if (path.endsWith('/git/ref/heads/develop') && ++developReads>1 && scenario==='develop-race') return {object:{sha:'f'.repeat(40)}};
            if (path.endsWith('/git/ref/heads/develop') && unreadableWrite) return {object:{sha:'f'.repeat(40)}};
            if (path.endsWith('/git/ref/heads/develop') && developReads>2 && scenario==='status-race') return {object:{sha:'f'.repeat(40)}};
            if (path.endsWith('/git/ref/heads/main') && scenario==='main-race') return {object:{sha:'f'.repeat(40)}};
            if (path.endsWith('/git/ref/heads/'+record.branch) && scenario==='head-race') return {object:{sha:'f'.repeat(40)}};
            if (path.endsWith('/compare/'+f.selected+'...'+main) && scenario==='ancestry') return {behind_by:1};
            if (path.endsWith('/git/refs/heads/develop') && method==='PATCH') {
                assert.equal(payload.force,false);
                f.git('git',['push','origin',payload.sha+':refs/heads/develop']);
                unreadableWrite=scenario==='readback';
                return {object:{sha:payload.sha}};
            }
            const result=f.api(path,method,payload);
            if (path.endsWith('/git/commits/'+main)) {
                if (scenario==='parent') result.parents.push({sha:main});
                if (scenario==='tree') result.tree.sha='f'.repeat(40);
            }
            return result;
        };
        if (scenario==='advanced' || scenario==='equivalent-sibling') {
            const advanced=f.git('git',['commit-tree',tree,'-p',f.selected,'-m','Parallel develop feature']);
            const changed=scenario==='equivalent-sibling' ? advanced : f.git('git',[
                'commit-tree',f.git('git',['rev-parse',f.selected+'^{tree}']),
                '-p',advanced,'-m','Keep parallel change',
            ]);
            f.git('git',['push','origin',changed+':refs/heads/develop']);
            assert.equal(synchronizeReleaseTree(record,f.repository,main,call).state,'BLOCKED_DEVELOP_ADVANCED');
            assert.equal(writes.length,0);continue;
        }
        if (scenario==='readback') {
            assert.throws(()=>synchronizeReleaseTree(record,f.repository,main,call),/not confirmed/);
            const count=writes.length;
            unreadableWrite=false;
            assert.equal(synchronizeReleaseTree(record,f.repository,main,call).sha,main);
            assert.equal(writes.length,count,'lost confirmation is recovered by actual ref readback without another write');
            continue;
        }
        if (scenario!=='normal') {
            assert.throws(()=>synchronizeReleaseTree(record,f.repository,main,call),/BLOCKED/);
            assert.ok(!writes.some(write=>write.method==='PATCH'),'invalid metadata and raced refs cannot update develop');
            continue;
        }
        const result=synchronizeReleaseTree(record,f.repository,main,call);
        assert.equal(result.state,'RECONCILED_FF');
        assert.equal(result.sha,main,'develop must point to actual M, not a content-equivalent sibling');
        assert.equal(f.git('git',['ls-remote','origin','refs/heads/develop']).split(/\s/)[0],main);
        assert.equal(f.git('git',['rev-list','--parents','-n','1',result.sha]),main+' '+f.previousMain+' '+linked);
        assert.equal(f.git('git',['rev-parse',result.sha+'^{tree}']),tree);
        assert.equal(f.git('git',['merge-base',linked,result.sha]),linked,'reviewed Q ancestry must be retained');
        assert.ok(!writes.some(write=>write.path.endsWith('/git/commits')),'synchronization creates no commits');
        assert.match([...statuses.values()][0].description,/assembly/);
        assert.doesNotMatch([...statuses.values()][0].description,/FULL/,'assembly does not invent a new FULL execution');
        const count=writes.length;
        assert.equal(synchronizeReleaseTree(record,f.repository,main,call).sha,result.sha);
        assert.equal(writes.length,count,'readback recovery creates no duplicate synchronization/status/ref writes');
        f.git('git',['tag','v_1.2.1',main]);f.git('git',['push','origin','refs/tags/v_1.2.1']);
        // The authenticated assembly/approval is shared by new and historical
        // receipt readers; only their actual Git synchronization differs.
        Object.assign(record,{coverage_schema:2,source_qualification:'protected-develop-v1',
            integration:{trust:'protected-develop',candidate_sha:f.selected,
                candidate_tree:f.git('git',['rev-parse',f.selected+'^{tree}'])},
            delta:null,installer_required:false});
        Object.assign(f.state.pr,{state:'closed',merged:true,merge_commit_sha:main,
            merged_at:'2026-10-03T00:00:00Z',merged_by:{login:'github-actions[bot]'}});
        const approval=effectiveOwnerReview(f.api('repos/'+f.repository+'/pulls/'+pr.number+'/reviews?per_page=100'),
            f.state.pr,'fixture',linked);
        const evidence={...record,schema_version:1,final_main_sha:main,final_tree:tree,
            tag:'v_1.2.1',tag_object_sha:main,tag_object_type:'commit',
            promotion_pr:{number:pr.number,reviewed_sha:linked,merge_commit_sha:main,
                merged_at:f.state.pr.merged_at,approval},
            develop_synchronization:{state:'RECONCILED_FF',result_sha:main,result_tree:tree,develop_base_sha:f.selected}};
        const jobs=['Select release source','Prepare release candidate','Release qualification gate',
            'Prepare manual release assets','Prepare Windows companion installer','Open qualified release PR']
            .map(name=>({name,status:'completed',conclusion:name==='Prepare Windows companion installer'?'skipped':'success',
                workflow_name:'Release qualification',run_id:Number(record.run_id),run_attempt:1}));
        const published=(path,...args)=>path.endsWith('/releases/tags/v_1.2.1')
            ? {tag_name:'v_1.2.1',draft:false,prerelease:false,assets:[{name:'release-evidence.json'}]}
            : call(path,...args);
        const inspection={...f,api:published,evidence,qualificationRecord:record,jobs};
        const request={branch:record.branch,mode:'plan',manualReview:''};
        assert.equal(inspectSync(request,f.repository,inspection).resultSha,main);
        const copy=f.git('git',['commit-tree',tree,'-p',f.selected,'-m','Historical content-copy synchronization']);
        const copied={...evidence,develop_synchronization:{...evidence.develop_synchronization,result_sha:copy}};
        const copiedApi=(path,...args)=>path.endsWith('/git/ref/heads/develop')?{object:{sha:copy}}:published(path,...args);
        assert.throws(()=>inspectSync(request,f.repository,{...inspection,api:copiedApi,evidence:copied}),/actual reviewed main/,
            'equal contents cannot impersonate actual M in a new FF receipt');
        copied.develop_synchronization.state='RECONCILED_EQUIVALENT';
        assert.equal(inspectSync(request,f.repository,{...inspection,api:copiedApi,evidence:copied}).state,
            'RECONCILED_EQUIVALENT','published content-copy receipts remain readable');
        const joined=f.git('git',['commit-tree',tree,'-p',main,'-p',copy,'-m','Later reviewed history retaining the old copy']);
        const joinedApi=(path,...args)=>['/git/ref/heads/develop','/git/ref/heads/main'].some(suffix=>path.endsWith(suffix))
            ? {object:{sha:joined}}:published(path,...args);
        assert.equal(inspectSync(request,f.repository,{...inspection,api:joinedApi,evidence:copied}).state,
            'RECONCILED_EQUIVALENT','later main merges do not invalidate an immutable historical receipt');
        f.state.review=false;
        assert.throws(()=>inspectSync(request,f.repository,inspection),/approval/,'missing human approval remains terminal');
        f.state.review=true;
        f.next('1.3');
        const next=startNewRelease({version:'1.3',selected:f.selected},f.repository,{...f,trustDevelop:true});
        assert.equal(next.state,'INITIALIZED','next release trusts qualified develop without historical reconciliation assets');
        assert.equal(linkReleaseHistory(next.candidate_sha,main,f.git),next.candidate_sha,
            'the following release needs no extra main-history link');
    } finally {f.cleanup();}
}
{
    const f=lifecycleFixture();
    try {
        assert.equal(requireMainFollowingDevelop(f.api,f.repository).linear_history,false);
        const legacy=path=>{
            const response=f.api(path);
            if (path.endsWith('/rulesets/2')) return {...response,rules:[...response.rules,{type:'required_linear_history'}]};
            if (path.endsWith('/rules/branches/develop')) return [...response,{type:'required_linear_history'}];
            return response;
        };
        assert.throws(()=>requireMainFollowingDevelop(legacy,f.repository),/requires linear history/);
        assert.equal(inspectReadiness(f.repository,legacy).develop.status,'BLOCKED');
        assert.equal(f.state.writes.length,0,'policy diagnostics never alter installed protections');
    } finally {f.cleanup();}
}
{
    const f=lifecycleFixture();
    try {
        const original=f.api;let reads=0;
        const path='repos/'+f.repository+'/git/matching-refs/heads/release/v_1.2.1';
        const provider=(endpoint,...args)=>endpoint===path && ++reads>2
            ?[{ref:'refs/heads/release/v_1.2.1',object:{type:'commit',sha:f.selected}}]:original(endpoint,...args);
        const result=startNewRelease({version:'1.2.1',selected:f.selected},f.repository,{...f,api:provider});
        assert.notEqual(result.origin_sha,f.selected,'successful normal push is not rejected by stale subsequent REST readback');
    } finally {f.cleanup();}
}
const workflow=readFileSync(new URL('../.github/workflows/release-promotion.yml',import.meta.url),'utf8');
assert.ok(!workflow.includes('pull_request_review:'),'early approval is observed by the already scheduled post-assembly observer');
assert.match(workflow,/statuses: write/);
process.stdout.write('PASS actual main-to-develop FF, retained receipts, next-release ancestry, policy blockers, idempotence and adverse metadata/ref leases\n');
