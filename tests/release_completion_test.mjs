/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/release_completion_test.mjs
 * Module Type: Regression Test
 * Purpose: Verify automatic reviewed merge, immutable tagging and linear synchronization with real Git.
 * Responsibilities:
 *   - Cover single approval, exact Q, parent order, full trees and interrupted tag creation
 *   - Refuse red CI, weakened effective policy, stale heads and conflicting tags
 *   - Keep parallel develop work and leave GitHub Release publication manual
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {startNewRelease} from '../.github/scripts/start-new-release.mjs';
import {openQualifiedPullRequest,completeRelease,waitForMergeability} from '../.github/scripts/release-completion.mjs';
import {inspectReadiness} from '../.github/scripts/release-readiness.mjs';
import {lifecycleFixture} from './support/release_lifecycle_fixture.mjs';

for (const scenario of ['normal','interrupted','parallel','unapproved','red','redacted-policy','wrong-tree','conflicting-tag','main-drift','head-drift']) {
    const f=lifecycleFixture();
    try {
        const initialized=startNewRelease({version:'1.2.1',selected:f.selected},f.repository,f);
        const record=f.prepare(initialized);
        assert.equal(inspectReadiness(f.repository,f.api).main.status,'VERIFIED');
        const pr=openQualifiedPullRequest(record,f.repository,'## Version 1.2.1\nReviewed fixture notes',f);
        const count=f.state.writes.length;
        assert.equal(openQualifiedPullRequest(record,f.repository,'Reviewed fixture notes',f).number,pr.number);
        assert.equal(f.state.writes.length,count,'No duplicate PR is created.');
        if (scenario==='normal') {
            let probes=0;const delays=[];
            await waitForMergeability(f.repository,pr.number,record.candidate_sha,record.initial_main_sha,path=>{
                const response=f.api(path);
                return path.endsWith('/pulls/'+pr.number) && ++probes<3 ? {...response,mergeable:null} : response;
            },async milliseconds=>{delays.push(milliseconds);});
            assert.deepEqual(delays,[2000,2000]);
            await assert.rejects(waitForMergeability(f.repository,pr.number,record.candidate_sha,record.initial_main_sha,
                path=>path.endsWith('/pulls/'+pr.number)?{...f.api(path),mergeable:false}:f.api(path),async()=>{}),/conflicts/);
            assert.equal(f.state.writes.length,count,'Mergeability polling is read-only.');
        }
        if (scenario==='unapproved') f.state.review=false;
        if (scenario==='red') f.state.ci=false;
        if (scenario==='redacted-policy') {
            f.state.policyVisible=false;
            const visible=inspectReadiness(f.repository,f.api);
            for (const branch of ['main','develop']) {
                assert.equal(visible[branch].status,'VERIFIED');
                assert.equal(visible[branch].policy.bypass_inventory,'NOT_RETURNED_SERVER_ENFORCED');
            }
        }
        if (scenario==='wrong-tree') f.state.wrongTree=true;
        if (scenario==='conflicting-tag') {
            f.git('git',['push','origin',f.previousMain+':refs/tags/v_1.2.1']);
        }
        if (scenario==='main-drift' || scenario==='head-drift') {
            const parent=scenario==='main-drift'?f.previousMain:record.candidate_sha;
            const tree=f.git('git',['rev-parse',parent+'^{tree}']);
            const changed=f.git('git',['commit-tree',tree,'-p',parent,'-m','Concurrent writer']);
            f.git('git',['push','origin',changed+':refs/heads/'+(scenario==='main-drift'?'main':record.branch)]);
        }
        if (scenario==='parallel') {
            const tree=f.git('git',['rev-parse',f.selected+'^{tree}']);
            const advanced=f.git('git',['commit-tree',tree,'-p',f.selected,'-m','Parallel feature']);
            f.git('git',['push','origin',advanced+':refs/heads/develop']);
        }
        if (scenario==='interrupted') {
            f.state.tagFailOnce=true;
            assert.throws(()=>completeRelease(record,f.repository,pr.number,f),/interruption/);
            assert.equal(f.state.pr.merged,true,'Restart observes the real completed merge.');
        }
        if (['unapproved','red','wrong-tree','conflicting-tag','main-drift','head-drift'].includes(scenario)) {
            assert.throws(()=>completeRelease(record,f.repository,pr.number,f),/BLOCKED/);
            if (['unapproved','red','main-drift','head-drift'].includes(scenario)) assert.equal(f.state.writes.length,count,'No write without exact approval, CI, policies and unchanged P/Q.');
            assert.equal(f.state.tagCreated,0,'Invalid final trees and conflicting tags cannot be tagged.');
            continue;
        }
        const result=completeRelease(record,f.repository,pr.number,f);
        assert.equal(result.state,'TAGGED');
        assert.equal(result.evidence.candidate_sha,record.candidate_sha);
        assert.equal(result.evidence.final_main_sha,result.main_sha);
        assert.equal(result.evidence.promotion_pr.approval.reviewer,'fixture');
        assert.equal(result.evidence.promotion_pr.approval.commit_sha,record.candidate_sha);
        assert.equal(f.git('git',['rev-list','--parents','-n','1',result.main_sha]),result.main_sha+' '+f.previousMain+' '+record.candidate_sha);
        assert.equal(f.git('git',['rev-parse',result.main_sha+'^{tree}']),f.git('git',['rev-parse',record.candidate_sha+'^{tree}']));
        assert.equal(result.synchronization,scenario==='parallel'?'BLOCKED_DEVELOP_ADVANCED':'RECONCILED_FF');
        assert.equal(f.api('repos/'+f.repository+'/git/ref/tags/v_1.2.1').object.sha,result.main_sha);
        const mutations=f.state.writes.length;
        completeRelease(record,f.repository,pr.number,f);
        assert.equal(f.state.writes.length,mutations,'Correct existing tag and merge are idempotent.');
        assert.equal(f.state.tagCreated,1);
        assert.ok(!f.state.writes.some(write=>write.path.includes('/releases')),'GitHub Release remains manual.');
        assert.notEqual(f.git('git',['merge-base',record.candidate_sha,result.main_sha]),result.main_sha,'Q never acquires M ancestry.');
        if (scenario==='normal') {
            f.next('1.3');
            const nextOrigin=startNewRelease({version:'1.3',selected:f.selected},f.repository,f);
            const next=f.prepare(nextOrigin);
            assert.equal(next.previous_stable_sha,result.main_sha,'The next predecessor is tagged M before manual publication.');
            assert.equal(next.source_base,record.candidate_sha,'Comparison follows previous release-side Q.');
            assert.notEqual(f.git('git',['merge-base',next.candidate_sha,result.main_sha]),result.main_sha);
            const nextPr=openQualifiedPullRequest(next,f.repository,'## Version 1.3\nNext reviewed release',f);
            const second=completeRelease(next,f.repository,nextPr.number,f);
            assert.equal(second.synchronization,'RECONCILED_FF');
            assert.equal(f.git('git',['rev-list','--parents','-n','1',second.main_sha]),second.main_sha+' '+result.main_sha+' '+next.candidate_sha);
        }
    } finally {f.cleanup();}
}
process.stdout.write('PASS real release parent/tree identity, single approval, retry, immutable tags, protected FF and parallel develop refusal\n');
