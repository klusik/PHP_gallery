/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/release_linear_sync_test.mjs
 * Module Type: Release Synchronization Regression Test
 * Purpose: Exercise unchanged reviewed trees across non-linear release and linear develop histories.
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
import {lifecycleFixture} from './support/release_lifecycle_fixture.mjs';

for (const scenario of ['normal','advanced','parent','tree','creator','missing-status','develop-race','main-race','head-race']) {
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
        const main=f.git('git',['commit-tree',tree,'-p',f.previousMain,'-p',linked,'-m','Reviewed release merge']);
        f.git('git',['push','origin',main+':refs/heads/main']);
        const statuses=new Map(),writes=[];
        let developReads=0;
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
            if (path.endsWith('/git/ref/heads/main') && scenario==='main-race') return {object:{sha:'f'.repeat(40)}};
            if (path.endsWith('/git/ref/heads/'+record.branch) && scenario==='head-race') return {object:{sha:'f'.repeat(40)}};
            if (path.endsWith('/git/refs/heads/develop') && method==='PATCH') {
                assert.equal(payload.force,false);
                f.git('git',['push','origin',payload.sha+':refs/heads/develop']);
                return {object:{sha:payload.sha}};
            }
            const result=f.api(path,method,payload);
            if (path.endsWith('/git/commits') && method==='POST') {
                if (scenario==='parent') result.parents.push({sha:main});
                if (scenario==='tree') result.tree.sha='f'.repeat(40);
            }
            return result;
        };
        if (scenario==='advanced') {
            const advanced=f.git('git',['commit-tree',tree,'-p',f.selected,'-m','Parallel develop feature']);
            // The actual concurrent tree must differ from Q, otherwise it already has the requested contents.
            const changed=f.git('git',['commit-tree',f.git('git',['rev-parse',f.selected+'^{tree}']),'-p',advanced,'-m','Keep parallel change']);
            f.git('git',['push','origin',changed+':refs/heads/develop']);
            assert.equal(synchronizeReleaseTree(record,f.repository,main,call).state,'BLOCKED_DEVELOP_ADVANCED');
            assert.equal(writes.length,0);continue;
        }
        if (scenario!=='normal') {
            assert.throws(()=>synchronizeReleaseTree(record,f.repository,main,call),/BLOCKED/);
            assert.ok(!writes.some(write=>write.method==='PATCH'),'invalid metadata and raced refs cannot update develop');
            continue;
        }
        const result=synchronizeReleaseTree(record,f.repository,main,call);
        assert.equal(result.state,'RECONCILED_EQUIVALENT');
        assert.equal(f.git('git',['rev-list','--parents','-n','1',result.sha]),result.sha+' '+f.selected);
        assert.equal(f.git('git',['rev-parse',result.sha+'^{tree}']),tree);
        assert.equal(f.git('git',['merge-base',linked,result.sha]),f.selected,'linear projection deliberately need not retain Q ancestry');
        assert.match([...statuses.values()][0].description,/assembly/);
        assert.doesNotMatch([...statuses.values()][0].description,/FULL/,'assembly does not invent a new FULL execution');
        const count=writes.length;
        assert.equal(synchronizeReleaseTree(record,f.repository,main,call).sha,result.sha);
        assert.equal(writes.length,count,'readback recovery creates no duplicate synchronization/status/ref writes');
        f.git('git',['tag','v_1.2.1',main]);f.git('git',['push','origin','refs/tags/v_1.2.1']);
        f.next('1.3');
        const next=startNewRelease({version:'1.3',selected:f.selected},f.repository,{...f,trustDevelop:true});
        assert.equal(next.state,'INITIALIZED','next release trusts qualified develop without historical reconciliation assets');
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
process.stdout.write('PASS real linear release projection, exact trees, next release, stale readback, idempotence and adverse metadata/ref leases\n');
