/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/release_draft_retirement_scenarios.mjs
 * Module Type: Regression Scenarios
 * Purpose: Exercise final draft branch cleanup against real disposable Git refs.
 * Responsibilities:
 *   - Preserve refs for pending drafts, incomplete proof, dependencies and races
 *   - Prove exact leased deletion, lost-response recovery and branchless retries
 *   - Check workflow ordering through the registered retirement regression owner
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {startNewRelease} from '../../.github/scripts/start-new-release.mjs';
import {openQualifiedPullRequest,completeRelease} from '../../.github/scripts/release-completion.mjs';
import {retireReadyDraft} from '../../.github/scripts/release-draft-retirement.mjs';
import {lifecycleFixture} from './release_lifecycle_fixture.mjs';

const f=lifecycleFixture();
try {
    const record=f.prepare(startNewRelease({version:'1.2.1',selected:f.selected},f.repository,f));
    const pr=openQualifiedPullRequest(record,f.repository,'Prepared fixture notes',f);
    const result=completeRelease(record,f.repository,pr.number,f);
    f.git('git',['push','origin',result.main_sha+':refs/heads/develop']);
    // Upgrade only the assembly metadata to the current schema after the real
    // reviewed merge. Existing completion fixtures own schema-4 FF production.
    Object.assign(record,{lifecycle_schema:4,coverage_schema:2,
        source_qualification:'protected-develop-v1',integration:{trust:'protected-develop',
            candidate_sha:f.selected,candidate_tree:f.git('git',['rev-parse',f.selected+'^{tree}'])},
        delta:null,installer_required:false});
    Object.assign(result,{synchronization:'RECONCILED_FF',synchronization_sha:result.main_sha,
        draft_status:'DRAFT_READY',draft_release_id:7});
    Object.assign(result.evidence,record,{develop_synchronization:{state:'RECONCILED_FF',
        result_sha:result.main_sha,result_tree:result.evidence.final_tree,develop_base_sha:f.selected}});
    const names=['production.zip','core-manifest.json','production-files.json','release-metadata.json',
        'release-notes.md','qualification-evidence.zip','SHA256SUMS','final-integrity.json',
        'PHP_Gallery_Manual.pdf','PHP_Gallery_Manual_CZ.pdf','PHP_Gallery_Manual_DE.pdf',
        'PHP_Gallery_Manual_SV.pdf','release-evidence.json','release-evidence.sha256',
        'winapp-update.json','installer.exe'];
    // Small byte sizes/digests are structural inert inventory vectors, not limits.
    result.draft_assets=names.map(name=>({name,size:7,digest:'sha256:'+'a'.repeat(64)}));
    const original=structuredClone(result);
    const context={GITHUB_RUN_ID:'999',GITHUB_RUN_ATTEMPT:'1',GITHUB_SHA:record.candidate_sha,
        GITHUB_EVENT_NAME:'workflow_dispatch'};
    let rows=result.draft_assets.map(asset=>({...asset,state:'uploaded'}));
    let draft=true,active=false,open=false,loseResponse=false,rejectDelete=false,race=false;
    let incomplete=false,inventoryFailure=false,ownStatus='in_progress',deleteCount=0;
    const provider=(path,method='GET',payload=null)=>{
        assert.equal(method,'GET','Cleanup never changes release, tag, main or develop through REST.');
        if (path.endsWith('/actions/runs/999')) return {id:999,run_attempt:1,head_sha:context.GITHUB_SHA,
            head_branch:record.branch,event:context.GITHUB_EVENT_NAME,status:ownStatus,
            path:'.github/workflows/release-promotion.yml',repository:{full_name:f.repository}};
        if (path.endsWith('/releases/7')) return {id:7,tag_name:result.tag,draft,prerelease:false};
        if (path.endsWith('/releases/7/assets?per_page=100')) {
            if (inventoryFailure) throw new Error('asset read unavailable');
            return structuredClone(rows);
        }
        if (path.includes('/pulls?state=open&')) return open?[{number:99}]:[];
        if (path.includes('/actions/workflows/') && path.includes('/runs?')) {
            const current=path.includes('release-promotion.yml') && path.includes('status=in_progress');
            const runs=current?[{id:999,head_branch:record.branch}]:[];
            if (active) runs.push({id:1000,head_branch:record.branch});
            return {total_count:incomplete?runs.length+1:runs.length,workflow_runs:runs};
        }
        return f.api(path,method,payload);
    };
    const git=(program,args,input=null)=>{
        if (program==='gh') {assert.deepEqual(args,['auth','setup-git']);return '';}
        assert.equal(program,'git');
        assert.deepEqual(args,['push','--force-with-lease=refs/heads/'+record.branch+':'+record.candidate_sha,
            'origin',':refs/heads/'+record.branch]);
        deleteCount++;
        if (rejectDelete) throw new Error('simulated policy refusal');
        if (race) f.git('git',['push','origin',result.main_sha+':refs/heads/'+record.branch]);
        const deleted=f.git(program,args,input);
        if (loseResponse) throw new Error('simulated lost response after accepted delete');
        return deleted;
    };
    const jobs=['Select release source','Prepare release candidate','Release qualification gate',
        'Prepare manual release assets','Prepare Windows companion installer','Open qualified release PR']
        .map(name=>({name,status:'completed',conclusion:name==='Prepare Windows companion installer'?'skipped':'success',
            workflow_name:'Release qualification',run_id:Number(record.run_id),run_attempt:1}));
    const adapters={...f,api:provider,git,qualificationRecord:record,jobs};
    for (const status of ['DRAFT_PENDING','DRAFT_CREATED','ALREADY_PUBLISHED']) {
        assert.equal(retireReadyDraft({...result,draft_status:status},f.repository,context,adapters).state,'SKIPPED');
    }
    assert.equal(retireReadyDraft({...result,synchronization:'BLOCKED_DEVELOP_ADVANCED'},f.repository,context,adapters).state,'SKIPPED');
    const assertBlocked=()=>assert.throws(()=>retireReadyDraft(result,f.repository,context,adapters),/BLOCKED|unavailable/);
    for (const field of ['branch','repository']) {
        const saved=result.evidence[field];result.evidence[field]='foreign';assertBlocked();result.evidence[field]=saved;
    }
    ownStatus='completed';assertBlocked();ownStatus='in_progress';
    draft=false;assertBlocked();draft=true;
    rows.pop();assertBlocked();rows=original.draft_assets.map(asset=>({...asset,state:'uploaded'}));
    rows[0].digest='sha256:'+'b'.repeat(64);assertBlocked();rows[0].digest=original.draft_assets[0].digest;
    rows[0].state='new';assertBlocked();rows[0].state='uploaded';
    rows.push(rows[0]);assertBlocked();rows.pop();
    inventoryFailure=true;assertBlocked();inventoryFailure=false;
    active=true;assertBlocked();active=false;
    open=true;assertBlocked();open=false;
    incomplete=true;assertBlocked();incomplete=false;
    f.state.ci=false;assertBlocked();f.state.ci=true;
    f.state.review=false;assertBlocked();f.state.review=true;
    const oldMain=result.main_sha;result.main_sha=f.previousMain;assertBlocked();result.main_sha=oldMain;
    const unrelated=path=>path.endsWith('/git/ref/heads/develop')?{object:{sha:f.selected}}:provider(path);
    assert.throws(()=>retireReadyDraft(result,f.repository,context,{...adapters,api:unrelated}),/BLOCKED/);
    assert.equal(deleteCount,0,'Every missing prerequisite refuses before the Git deletion boundary.');
    rejectDelete=true;assertBlocked();rejectDelete=false;
    assert.equal(f.git('git',['ls-remote','origin','refs/heads/'+record.branch]).split(/\s/)[0],record.candidate_sha);
    race=true;assertBlocked();race=false;
    assert.equal(f.git('git',['ls-remote','origin','refs/heads/'+record.branch]).split(/\s/)[0],result.main_sha,
        'server lease preserves a concurrently advanced release branch');
    assertBlocked();
    // Restore this isolated test ref using its exact observed lease only.
    f.git('git',['push','--force-with-lease=refs/heads/'+record.branch+':'+result.main_sha,
        'origin',record.candidate_sha+':refs/heads/'+record.branch]);
    loseResponse=true;
    assert.equal(retireReadyDraft(result,f.repository,context,adapters).state,'RETIRED');
    const writes=deleteCount;
    assert.equal(retireReadyDraft(result,f.repository,context,adapters).state,'ALREADY_DELETED');
    assert.equal(deleteCount,writes,'Branchless retry reads immutable proof without repeating deletion.');
    assert.equal(f.git('git',['ls-remote','origin','refs/heads/'+record.branch]),'');
    for (const ref of ['refs/heads/main','refs/heads/develop','refs/tags/'+result.tag]) {
        assert.equal(f.git('git',['ls-remote','origin',ref]).split(/\s/)[0],result.main_sha);
    }
} finally {f.cleanup();}

const workflow=readFileSync(new URL('../../.github/workflows/release-promotion.yml',import.meta.url),'utf8');
assert.match(workflow,/draft_ready: \$\{\{ steps\.completion\.outputs\.draft_ready \}\}/);
assert.match(workflow,/retire-draft-branch:\s+needs: complete\s+if: \$\{\{ needs\.complete\.outputs\.draft_ready == 'true' \}\}/);
assert.ok(workflow.indexOf('Retain immutable completion identity')<workflow.indexOf('retire-draft-branch:'));
assert.match(workflow,/Download this run's retained completion before cleanup[\s\S]*name: release-completion-record/);
assert.match(workflow,/run: node \.github\/scripts\/release-draft-retirement\.mjs/);
process.stdout.write('PASS ready draft cleanup, durable evidence, branchless recovery and exact Git deletion lease\n');
