/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/hosted_release_policy_test.mjs
 * Module Type: Regression Test
 * Purpose: Refuse stale, partial and unaudited hosted release promotion without network writes.
 * Responsibilities:
 *   - Exercise real qualification validators with immutable synthetic server records
 *   - Protect CI-first instructions, branch triggers and isolated release credentials
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFileSync,writeFileSync,mkdtempSync,rmSync,readdirSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join,basename} from 'node:path';
import {createHash} from 'node:crypto';
import {validateRequest,verifyQualification,verifyFinalContent,requireQualificationChecks,requireEligibleQualificationEvent,requireCurrentMainBase,promote,publish,inspectMergedPromotion} from '../.github/scripts/release-promotion.mjs';
import {requireOwnerDispatch,requireOwnerEnvironment,requireOwnerRuleset,requireBotPullRequest,effectiveOwnerReview} from '../.github/scripts/release-owner-authorization.mjs';

const candidate = 'a'.repeat(40);
const request = {runId:'123',candidate,branch:'release/v_1.2.3',mode:'plan',override:false,reason:'',acceptedFailures:[],manualReview:''};
const run = {path:'.github/workflows/release-qualification.yml',event:'push',head_branch:request.branch,status:'completed',conclusion:'success',run_attempt:1};
const record = {repository:'owner/gallery',branch:request.branch,candidate_sha:candidate,
    source_base:'b'.repeat(40),version:'1.2.3',run_id:'123',run_attempt:'1',ready:true,
    origin_commit_sha:'e'.repeat(40),selected_develop_sha:'f'.repeat(40),
    initial_main_sha:'9'.repeat(40),previous_stable_tag:'v_1.2.2',previous_stable_sha:'9'.repeat(40),
    previous_release_candidate_sha:'b'.repeat(40),previous_tag_object_sha:'9'.repeat(40),
    previous_tag_object_type:'commit',previous_tree_sha:'8'.repeat(40),predecessor_kind:'qualified',origin_blob_sha:'7'.repeat(40)};
const owners = ['Prepare release candidate','Read-only generated-state and source preflight',
    'Positive production package (ubuntu-24.04)','Positive production package (windows-2025)','Positive production package (macos-latest)',
    'PHP 8.3 workflows (mysql:8.4, Unicode)','PHP 8.3 workflows (mariadb:10.11, Unicode)','PHP 8.5 workflows (mariadb:11.4, Unicode)',
    'PHP 8.1 source (unicode)','PHP 8.5 source (ascii)','Required Chromium fixtures','Authoritative release audit','Complete required CI matrix','Release qualification gate'];
const jobs = owners.map(name => ({name,status:'completed',conclusion:'success',html_url:'https://github.com/owner/gallery/actions/runs/123'}));
assert.equal(validateRequest(request),'1.2.3');
requireEligibleQualificationEvent(run);
assert.throws(()=>requireEligibleQualificationEvent({...run,event:'workflow_dispatch'}),/BLOCKED_PR_CHECK_ELIGIBILITY/);
verifyFinalContent(candidate,candidate,'b'.repeat(40),'b'.repeat(40));
assert.throws(() => verifyFinalContent('c'.repeat(40),candidate,'b'.repeat(40),'b'.repeat(40)),/main advanced/);
assert.throws(() => verifyFinalContent(candidate,candidate,'c'.repeat(40),'b'.repeat(40)),/tree differs/);
assert.deepEqual(verifyQualification(request,run,jobs,record,record.repository,candidate),[]);
for (const branch of ['main','develop','feature/example','release/v_01.2.3','release/v_1.2.3;command']) {
    assert.throws(() => validateRequest({...request,branch}),/BLOCKED/);
}
assert.throws(() => validateRequest({...request,candidate:'main'}),/BLOCKED/);
assert.throws(() => validateRequest({...request,mode:'publish'}),/human acceptance/);
for (const patch of [{path:'.github/workflows/gallery-workflows.yml'},{event:'pull_request'},
    {status:'in_progress'},{head_branch:'release/v_2.0'},{conclusion:'cancelled'},{run_attempt:2}]) {
    assert.throws(() => verifyQualification(request,{...run,...patch},jobs,record,record.repository,candidate),/BLOCKED/);
}
for (const patch of [{candidate_sha:'c'.repeat(40)},{ready:false},{ready:'false'},{source_base:'HEAD'},
    {repository:'fork/gallery'},{run_id:'124'},{version:'2.0'}]) {
    assert.throws(() => verifyQualification(request,run,jobs,{...record,...patch},record.repository,candidate),/BLOCKED/);
}
assert.throws(() => verifyQualification(request,run,jobs,record,record.repository,'d'.repeat(40)),/stale/);
for (const patch of [{origin_commit_sha:'bad'}, {selected_develop_sha:'develop'},
    {previous_stable_tag:'v_1.2.3;bad'}, {previous_stable_sha:'c'.repeat(40)}]) {
    assert.throws(() => verifyQualification(request,run,jobs,{...record,...patch},record.repository,candidate),/provenance/);
}
assert.throws(() => verifyQualification(request,run,jobs.slice(1),record,record.repository,candidate),/missing/);
assert.throws(() => verifyQualification(request,run,[...jobs,jobs[0]],record,record.repository,candidate),/ambiguous/);
for (const conclusion of ['failure','skipped','cancelled',null]) {
    const redJobs = jobs.map(job => job.name === 'Required Chromium fixtures' ? {...job,conclusion} : job);
    assert.throws(() => verifyQualification(request,run,redJobs,record,record.repository,candidate),/red/);
}
const failedJobs = jobs.map(job => job.name === 'Required Chromium fixtures' || job.name === 'Release qualification gate' ? {...job,conclusion:'failure'} : job);
const exception = {...request,override:true,reason:'Reviewed browser provider outage',acceptedFailures:['Required Chromium fixtures','Release qualification gate']};
assert.equal(verifyQualification(exception,{...run,conclusion:'failure'},failedJobs,record,record.repository,candidate).length,2);
assert.equal(failedJobs[10].conclusion,'failure');
assert.throws(() => verifyQualification({...exception,acceptedFailures:['Required Chromium fixtures']},{...run,conclusion:'failure'},failedJobs,record,record.repository,candidate),/every exact/);
assert.throws(() => verifyQualification(exception,run,jobs,record,record.repository,candidate),/every exact/);
assert.throws(() => verifyQualification(exception,{...run,conclusion:'failure'},failedJobs.map(job => job.name === 'Prepare release candidate' ? {...job,conclusion:'failure'} : job),record,record.repository,candidate),/cannot be overridden/);

// Exercise the real publication state machine with inert server/command adapters.
const directory = mkdtempSync(join(tmpdir(),'gallery-hosted-release-'));
const previousAssets = process.env.RELEASE_ASSETS;
const previousActor = process.env.GITHUB_ACTOR;
const previousSummary = process.env.GITHUB_STEP_SUMMARY;
const previousOutput = process.env.GITHUB_OUTPUT;
const previousRunnerTemp = process.env.RUNNER_TEMP;
// Inert publication must never append simulated evidence to a real Actions step.
delete process.env.GITHUB_STEP_SUMMARY;
delete process.env.GITHUB_OUTPUT;
process.env.RELEASE_ASSETS = directory;
process.env.RUNNER_TEMP = directory;
process.env.GITHUB_ACTOR = 'fixture-maintainer';
let tagSha = null;
let releaseState = null;
let interruptUpload = true;
let unexpectedMain = false;
let mainAdvanced = false;
let promoted = false;
let wrongParents = false;
let changedWinapp = false;
const writes = [];
const mergeSha = 'c'.repeat(40);
const tree = 'd'.repeat(40);
const releasePr = {number:7,node_id:'fixture-pr',state:'closed',merged_at:'2026-10-08T00:00:00Z',
    merged:true,merged_by:{login:'owner'},auto_merge:null,
    created_at:'2026-10-07T15:00:00Z',user:{login:'github-actions[bot]'},
    merge_commit_sha:mergeSha,head:{ref:request.branch,sha:candidate,repo:{full_name:record.repository}},
    base:{ref:'main',sha:record.initial_main_sha,repo:{full_name:record.repository}},html_url:'https://github.com/owner/gallery/pull/7'};
const fakeApi = (path,method = 'GET',payload = null) => {
    if (method !== 'GET') writes.push({path,method,payload});
    if (path.includes('/check-runs?')) return {total_count:2,check_runs:['Release qualification','Complete required CI matrix'].map(name=>({
        name,head_sha:candidate,app:{id:15368},status:'completed',conclusion:'success',
        external_id:'release:'+request.runId+':'+record.run_attempt+':'+candidate,
        details_url:'https://github.com/'+record.repository+'/runs/114046888632'}))};
    if (path.includes('/pulls?')) {
        const listed=structuredClone(releasePr);
        delete listed.merged_by;
        delete listed.merged;
        return [listed];
    }
    if (path.endsWith('/pulls/7') && method==='GET') return structuredClone(releasePr);
    if (path.endsWith('/pulls/7/reviews?per_page=100')) return [
        {id:72,user:{login:'owner'},state:'APPROVED',commit_id:candidate,
            submitted_at:'2026-10-07T18:00:00Z',html_url:'https://github.com/owner/gallery/pull/7#pullrequestreview-72'}];
    if (path.endsWith('/commits/main')) return {sha:mergeSha,commit:{tree:{sha:unexpectedMain ? 'e'.repeat(40) : tree}},
        parents:wrongParents ? [{sha:record.initial_main_sha}] : [{sha:record.initial_main_sha},{sha:candidate}]};
    if (path.endsWith('/git/ref/tags/v_1.2.2')) return {object:{type:'commit',sha:record.previous_stable_sha}};
    if (path.endsWith('/commits/'+record.previous_stable_sha)) return {sha:record.previous_stable_sha,commit:{tree:{sha:record.previous_tree_sha}},parents:[{sha:'0'.repeat(40)},{sha:record.previous_release_candidate_sha}]};
    if (path.endsWith('/commits/'+record.previous_release_candidate_sha)) return {sha:record.previous_release_candidate_sha,commit:{tree:{sha:record.previous_tree_sha}}};
    if (path.endsWith('/releases/tags/v_1.2.2')) return {tag_name:'v_1.2.2',draft:false,prerelease:false,assets:[{name:'release-evidence.json'}]};
    if (path.endsWith('/commits/' + candidate)) return {sha:candidate,commit:{tree:{sha:tree}}};
    if (path.includes('/compare/')) return {behind_by:mainAdvanced && path.endsWith('/compare/main...' + candidate) ? 1 : 0,total_commits:1,files:changedWinapp ? [{filename:'winapp/gallery_uploader/self_update.py'}] : []};
    if (path.includes('/git/matching-refs/')) return tagSha ? [{ref:'refs/tags/v_1.2.3',object:{type:'commit',sha:tagSha}}] : [];
    if (path.endsWith('/git/refs') && method === 'POST') { tagSha = payload.sha; return {}; }
    if (path.endsWith('/git/ref/heads/main')) return {object:{sha:mainAdvanced ? '0'.repeat(40) : promoted ? mergeSha : record.initial_main_sha}};
    if (path.endsWith('/git/ref/heads/' + request.branch)) return {object:{sha:candidate}};
    if (path.endsWith('/git/ref/tags/v_1.2.3')) return {object:{sha:tagSha}};
    if (path.endsWith('/releases?per_page=100')) return releaseState ? [structuredClone(releaseState)] : [];
    if (path.includes('/releases/tags/')) return structuredClone(releaseState);
    if (path.endsWith('/releases/9') && method === 'PATCH') { releaseState.draft = payload.draft; return structuredClone(releaseState); }
    if (path.endsWith('/pulls/7') && method === 'PATCH') return {};
    if (path.endsWith('/pulls/7/merge') && method === 'PUT') return {merged:true};
    assert.fail('Unexpected API path: ' + path);
};
const fakeCommand = (executable,args) => {
    if (executable==='git') return changedWinapp ? 'winapp/gallery_uploader/self_update.py' : '';
    assert.equal(executable,'gh');
    if (args[0] === 'api') { writes.push({graphql:args}); return '{}'; }
    assert.equal(args[0],'release');
    if (args[1] === 'create') {
        assert.ok(args.includes('--draft'));
        releaseState = {id:9,draft:true,tag_name:'v_1.2.3',assets:[],html_url:'https://github.com/owner/gallery/releases/tag/v_1.2.3'};
    } else if (args[1] === 'upload') {
        if (interruptUpload && releaseState.assets.length === 2) throw new Error('simulated interrupted upload');
        const data = readFileSync(args[3]);
        releaseState.assets.push({name:basename(args[3]),size:data.length,digest:'sha256:' + createHash('sha256').update(data).digest('hex')});
    } else assert.fail('Unexpected release command.');
    return '';
};
const adapters = {api:fakeApi,command:fakeCommand,record,context:{GITHUB_REF:'refs/heads/main',GITHUB_ACTOR:'fixture-maintainer'},evidence:{repository:record.repository,version:'1.2.2',
    final_main_sha:record.previous_stable_sha,final_tree:record.previous_tree_sha,candidate_sha:record.previous_release_candidate_sha,
    ready:true,automated_result:'success',override:false,run_id:'122'}};
const publishRequest = {...request,mode:'publish',manualReview:'Independent browser acceptance fixture'};
try {
    assert.equal(requireCurrentMainBase(request,record.repository,adapters),record.initial_main_sha);
    mainAdvanced=true;
    assert.throws(() => requireCurrentMainBase(request,record.repository,adapters),/BLOCKED_MAIN_ADVANCED/);
    await assert.rejects(promote({...publishRequest,mode:'promote'},record.repository,[],adapters),/BLOCKED_MAIN_ADVANCED/);
    assert.equal(writes.length,0,'Moved main cannot create or merge a release PR.');
    mainAdvanced=false;
    const recovery = JSON.parse(readFileSync(join(directory,'release-recovery.json'),'utf8'));
    assert.equal(recovery.state,'BLOCKED_MAIN_ADVANCED');
    assert.equal(recovery.next_required_state,'NEW_CANDIDATE_REQUIRED');
    assert.equal(recovery.supersession,'PENDING_MAINTAINER_REVIEW');
    assert.equal(recovery.original_qualification_run_id,request.runId);
    assert.equal(recovery.original_qualified_candidate_sha,candidate);
    assert.equal(recovery.observed_main_sha,mergeSha);
    assert.equal(recovery.observed_main_tree,tree);
    rmSync(join(directory,'release-recovery.json'));
    for (const name of ['production.zip','core-manifest.json','production-files.json','release-metadata.json','release-notes.md','SHA256SUMS','qualification-evidence.zip']) writeFileSync(join(directory,name),'fixture public data ' + name);
    const hashes = Object.fromEntries(readdirSync(directory).map(name => [name,createHash('sha256').update(readFileSync(join(directory,name))).digest('hex')]));
    writeFileSync(join(directory,'final-integrity.json'),JSON.stringify({candidate_sha:candidate,source_base:record.source_base,result:'PASS',hashes}));
    promoted=true;
    wrongParents=true;
    assert.throws(()=>inspectMergedPromotion(publishRequest,record.repository,adapters),/merge parents/);
    wrongParents=false;
    releasePr.merged_by.login='github-actions[bot]';
    assert.throws(()=>inspectMergedPromotion(publishRequest,record.repository,adapters),/manually merged/);
    releasePr.merged_by.login='owner';
    releasePr.auto_merge={enabled_by:{login:'owner'}};
    assert.throws(()=>inspectMergedPromotion(publishRequest,record.repository,adapters),/github-actions\[bot\]/);
    releasePr.auto_merge=null;
    const inspectionReads=[];
    const inspectionApi=(path,...args)=>{inspectionReads.push(path);return fakeApi(path,...args);};
    const listPath='repos/'+record.repository+'/pulls?state=closed&base=main&head='
        +encodeURIComponent('owner:'+request.branch)+'&per_page=100';
    const detailPath='repos/'+record.repository+'/pulls/7';
    assert.equal(Object.hasOwn(fakeApi(listPath)[0],'merged_by'),false,'GitHub PR listings omit the merger.');
    const merged=inspectMergedPromotion(publishRequest,record.repository,{...adapters,api:inspectionApi});
    assert.deepEqual(inspectionReads.slice(0,3),[listPath,detailPath,detailPath+'/reviews?per_page=100']);
    assert.equal(merged.pr.merged_by.login,'owner','Only the complete, identity-bound PR detail proves the human merger.');
    assert.equal(merged.ownerReview.id,72);
    assert.equal(merged.main.sha,mergeSha);
    const completeListingApi=(path,...args)=>path===listPath ? [structuredClone(releasePr)] : fakeApi(path,...args);
    assert.equal(inspectMergedPromotion(publishRequest,record.repository,{...adapters,api:completeListingApi}).pr.number,7);
    for (const response of [null,{},[],[null],[{merged_at:null}],[releasePr,releasePr]]) {
        const invalidListingApi=(path,...args)=>path===listPath ? response : fakeApi(path,...args);
        assert.throws(()=>inspectMergedPromotion(publishRequest,record.repository,{...adapters,api:invalidListingApi}),/BLOCKED/);
    }
    for (const mutate of [
        pr=>{delete pr.number;},pr=>{delete pr.node_id;},pr=>{delete pr.head;},
        pr=>{delete pr.head.ref;},pr=>{delete pr.base.sha;},pr=>{delete pr.base.repo;},
        pr=>{delete pr.merge_commit_sha;},pr=>{delete pr.merged_at;},pr=>{delete pr.created_at;},
        pr=>{pr.number=8;},pr=>{pr.node_id='another-pr';},pr=>{pr.head.ref='release/v_1.2.4';},
        pr=>{pr.head.sha='e'.repeat(40);},pr=>{pr.head.repo.full_name='foreign/gallery';},
        pr=>{pr.base.ref='develop';},pr=>{pr.base.sha='e'.repeat(40);},
        pr=>{pr.base.repo.full_name='foreign/gallery';},pr=>{pr.state='open';},pr=>{pr.merged=false;},
        pr=>{pr.merge_commit_sha='e'.repeat(40);},pr=>{pr.merged_at='2026-10-08T01:00:00Z';},
        pr=>{pr.created_at='2026-10-07T16:00:00Z';},pr=>{pr.html_url+='0';},
        pr=>{pr.user.login='owner';},pr=>{pr.auto_merge={enabled_by:{login:'owner'}};},
        pr=>{delete pr.merged_by;},pr=>{pr.merged_by={login:'another-human'};},
    ]) {
        const inconsistentApi=(path,...args)=>{
            const response=fakeApi(path,...args);
            if (path===detailPath) mutate(response);
            return response;
        };
        assert.throws(()=>inspectMergedPromotion(publishRequest,record.repository,{...adapters,api:inconsistentApi}),/BLOCKED/);
    }
    for (const mutate of [
        pr=>{delete pr.number;},pr=>{delete pr.node_id;},pr=>{delete pr.head.ref;},
        pr=>{delete pr.base.repo;},pr=>{pr.number=Number.MAX_SAFE_INTEGER+1;},
        pr=>{pr.merged_by={login:'another-human'};},
    ]) {
        const incompleteListingApi=(path,...args)=>{
            const response=fakeApi(path,...args);
            if (path===listPath) mutate(response[0]);
            return response;
        };
        assert.throws(()=>inspectMergedPromotion(publishRequest,record.repository,{...adapters,api:incompleteListingApi}),/BLOCKED/);
    }
    for (const response of [null,{},[]]) {
        const missingDetailApi=(path,...args)=>path===detailPath ? response : fakeApi(path,...args);
        assert.throws(()=>inspectMergedPromotion(publishRequest,record.repository,{...adapters,api:missingDetailApi}),/BLOCKED/);
    }
    for (const failedPath of [listPath,detailPath]) {
        const unavailableApi=(path,...args)=>{
            if (path===failedPath) throw new Error('simulated GitHub API failure');
            return fakeApi(path,...args);
        };
        assert.throws(()=>inspectMergedPromotion(publishRequest,record.repository,{...adapters,api:unavailableApi}),/BLOCKED release promotion PR API unavailable/);
    }
    assert.equal(writes.length,0,'Missing, conflicting or unavailable PR detail cannot mutate the server.');
    assert.throws(()=>requireCurrentMainBase(request,record.repository,adapters),/BLOCKED_MAIN_ADVANCED/);
    unexpectedMain = true;
    await assert.rejects(publish(publishRequest,record.repository,record,[],adapters),/tree differs/);
    assert.equal(writes.length,0);
    unexpectedMain = false;
    changedWinapp = true;
    await assert.rejects(publish(publishRequest,record.repository,record,[],adapters),/WinApp requires/);
    assert.equal(writes.length,0);
    changedWinapp = false;
    const installerBytes = Buffer.from('fixture Windows installer bytes');
    writeFileSync(join(directory,'fixture-installer.exe'),installerBytes);
    writeFileSync(join(directory,'winapp-update.json'),JSON.stringify({assets:[{name:'fixture-installer.exe',version:'1.0',
        size:installerBytes.length,sha256:createHash('sha256').update(installerBytes).digest('hex')}]}));
    changedWinapp = true;
    await assert.rejects(publish(publishRequest,record.repository,record,[],adapters),/interrupted upload/);
    assert.equal(tagSha,mergeSha);
    assert.equal(releaseState.draft,true);
    assert.ok(!writes.some(write => write.payload?.draft === false));
    interruptUpload = false;
    await publish(publishRequest,record.repository,record,[],adapters);
    assert.equal(releaseState.draft,false);
    assert.equal(releaseState.assets.length,readdirSync(directory).length);
    const permanent = JSON.parse(readFileSync(join(directory,'release-evidence.json'),'utf8'));
    assert.equal(permanent.final_main_sha,mergeSha);
    assert.equal(permanent.candidate_sha,candidate);
    assert.equal(permanent.manual_review,publishRequest.manualReview);
    assert.equal(permanent.promotion_pr.author,'github-actions[bot]');
    assert.equal(permanent.promotion_pr.approval.reviewer,'owner');
    assert.equal(permanent.promotion_pr.approval.commit_sha,candidate);
    const mutationCount = writes.length;
    await publish(publishRequest,record.repository,record,[],adapters);
    assert.equal(writes.length,mutationCount,'Identical retry must not modify published refs or release.');
    tagSha = 'f'.repeat(40);
    await assert.rejects(publish(publishRequest,record.repository,record,[],adapters),/immutable tag collision/);
    assert.equal(writes.length,mutationCount);
    tagSha = mergeSha;
    releaseState.assets[0].digest = 'sha256:' + '0'.repeat(64);
    await assert.rejects(publish(publishRequest,record.repository,record,[],adapters),/existing release asset differs/);
    assert.equal(writes.length,mutationCount);
    writeFileSync(join(directory,'fixture-installer.exe'),'altered installer bytes');
    await assert.rejects(publish(publishRequest,record.repository,record,[],adapters),/installer metadata/);
    assert.equal(writes.length,mutationCount);
    promoted=false;
    releasePr.state = 'open';
    releasePr.merged_at = null;
    const beforePropose = writes.length;
    await promote({...publishRequest,mode:'promote'},record.repository,[],adapters);
    assert.equal(writes.length,beforePropose,
        'Existing exact bot PR must wait for explicit human PR review and merge.');
    await assert.rejects(promote({...exception,mode:'promote',manualReview:'Owner red-check exception'},
        record.repository,failedJobs.filter(job => job.conclusion === 'failure'),adapters),/cannot bypass/);
    assert.equal(writes.length,beforePropose,'Red CI may never create a protected merge.');
    releasePr.user.login='owner';
    await assert.rejects(promote({...publishRequest,mode:'promote'},record.repository,[],adapters),
        /github-actions\[bot\]/);
    releasePr.user.login='github-actions[bot]';
    assert.ok(!writes.some(write => write.path?.includes('/git/refs/heads/main')));
    assert.ok(!writes.some(write => write.graphql),'Single-owner mode cannot enable PR auto-merge.');
} finally {
    if (previousAssets === undefined) delete process.env.RELEASE_ASSETS; else process.env.RELEASE_ASSETS = previousAssets;
    if (previousActor === undefined) delete process.env.GITHUB_ACTOR; else process.env.GITHUB_ACTOR = previousActor;
    if (previousSummary === undefined) delete process.env.GITHUB_STEP_SUMMARY; else process.env.GITHUB_STEP_SUMMARY = previousSummary;
    if (previousOutput === undefined) delete process.env.GITHUB_OUTPUT; else process.env.GITHUB_OUTPUT = previousOutput;
    if (previousRunnerTemp === undefined) delete process.env.RUNNER_TEMP; else process.env.RUNNER_TEMP = previousRunnerTemp;
    // This unique test-owned directory was created above; never remove another path.
    rmSync(directory,{recursive:true,force:true});
}

const root = new URL('../',import.meta.url);
const read = path => readFileSync(new URL(path,root),'utf8').replaceAll('\r\n','\n');
const agent = read('AGENTS.md');
const contract = agent.slice(agent.indexOf('### Mandatory Agent Verification Contract'),agent.indexOf('The tracked `tests/`'));
assert.match(contract,/CI-first/);
assert.match(contract,/NEVER directly mutate `develop` or `main`/);
assert.match(contract,/Do not run local audits/);
assert.doesNotMatch(contract,/Before handing off.*run.*--profile=full/);

// Retain optional focused diagnostics while rejecting active instructions that
// would make local full audits a prerequisite for ordinary source handoff.
const auditHeader = read('scripts/audit.php').split('declare(strict_types=1);')[0];
assert.match(auditHeader,/CI-first.*hosted GitHub Actions/i);
assert.doesNotMatch(auditHeader,/normal agent verification entrypoint/i);
const testing = read('TESTING.md');
assert.match(testing,/### Hosted coverage ownership/);
assert.match(testing,/explicitly requested local recovery/i);
for (const drift of [
    /Use `php scripts\/audit\.php --profile=full` for an ordinary source handoff/i,
    /In a source checkout[^\n]*run `php scripts\/audit\.php --profile=full`/i,
    /Re-run[^\n]*the (?:complete|full) `php scripts\/audit\.php --profile=full` suite/i,
    /Then run `php scripts\/audit\.php --profile=full`/i,
    /and `php scripts\/audit\.php --profile=full` after changing/i,
    /Run `php -l` on every changed PHP file and `node --check`/i,
]) {
    assert.doesNotMatch(testing,drift,'Active TESTING.md instructions must use hosted qualification');
}
assert.doesNotMatch(read('docs/TITLE_COMPLETION.md'),/`--profile=quick` during implementation and `--profile=full` before code handoff/i);
assert.doesNotMatch(read('docs/IMAGE_DECODE_POLICY.md'),/Use `php scripts\/audit\.php --profile=full` for the integrated handoff/i);
assert.match(read('README.md'),/GitHub Actions through candidate preparation and the required hosted matrix/);
assert.match(read('AGENTS.md'),/local audit invocations are reserved for explicit diagnosis\/recovery/);
for (const document of ['TESTING.md','CONTRIBUTING.md','docs/AGENT_AUTHORING.md']) {
    assert.match(read(document),/CI-first/);
    assert.match(read(document),/BLOCKED/);
}
const preparation = read('.github/workflows/candidate-preparation.yml');
for (const prefix of ['feature','fix','bugfix','hotfix','codex']) {
    assert.ok(preparation.includes(`- '${prefix}/**'`),`Missing hosted preparation for ${prefix}`);
    assert.ok(preparation.includes(`${prefix}/*`),`Missing write guard for ${prefix}`);
}
assert.match(preparation,/HEAD:refs\/heads\/\$\{GITHUB_REF_NAME\}/);
assert.match(preparation,/name:'Candidate qualification'/);
const release = read('.github/workflows/release-qualification.yml');
assert.match(release,/name:'Release qualification'/);
assert.match(release,/release-qualification-record/);
const stageA = release.indexOf('name: Cheap release source preflight');
const stageB = release.indexOf('name: Medium-cost release source preflight');
const generation = release.indexOf('name: Apply deterministic release preparation');
assert.ok(stageA !== -1 && stageA < stageB && stageB < generation,
    'Both release preflight tiers must finish before generation or expensive dependencies.');
assert.match(release,/--profile=release-stage-b/);
assert.match(release,/name: release-source-preflight/);
assert.match(release,/RELEASE_INITIAL_EPOCH="\$\(git show -s --format=%ct/);
assert.match(read('.github/scripts/prepare_release_candidate.php'),/resolve_release_moment\(/);
assert.doesNotMatch(read('.github/scripts/prepare_release_candidate.php'),/new DateTimeImmutable\('now'/);
const promotion = read('.github/workflows/release-promotion.yml');
assert.match(promotion,/environment: release-promotion/);
assert.match(promotion,/persist-credentials: false/);
assert.match(promotion,/refs\/heads\/main/);
assert.match(promotion,/RELEASE_MODE: plan/);
assert.match(promotion,/cancel-in-progress: false/);
assert.doesNotMatch(promotion,/pull_request_target|secrets: inherit|git push/);
const helper = read('.github/scripts/release-promotion.mjs');
assert.match(helper,/verifyFinalContent\(main.sha,pr.merge_commit_sha,main.commit.tree.sha,candidate.commit.tree.sha\)/);
const ownerGate=read('.github/scripts/release-owner-authorization.mjs');
assert.match(helper,/requireOwnerEnvironment/);
assert.match(ownerGate,/prevent_self_review !== false/);
assert.match(ownerGate,/can_admins_bypass !== false/);
assert.match(helper,/effectiveOwnerReview/);
assert.doesNotMatch(helper,/enablePullRequestAutoMerge|merge_method:'merge'/);
assert.match(helper,/immutable tag collision/);
assert.doesNotMatch(helper,/--clobber|force:true|conclusion:'success'.*override/);
for (const suffix of ['','_CZ','_DE','_SV']) {
    const manual = read(`docs/PHP_Gallery_Manual${suffix}.tex`);
    assert.match(manual,/CI-first/);
    assert.match(manual,/release-promotion/);
    assert.doesNotMatch(manual,/Use \\codeword\{--profile=full\} for~complete|Pro úplné ověření před předáním použijte|Verwenden Sie \\codeword\{--profile=full\} für die vollständige Übergabeprüfung|Använd \\codeword\{--profile=full\} för fullständig överlämningskontroll/);
}

// Owner-only authorization: real REST shapes, no network and no GitHub writes.
const ownerRepo='owner/gallery';
const ownerRule=Object.assign(JSON.parse(read('.github/release-ruleset.example.json')),
    {id:24808772,source:ownerRepo,current_user_can_bypass:'never'});
const ownerEnv={protection_rules:[{type:'required_reviewers',prevent_self_review:false,
    reviewers:[{type:'User',reviewer:{login:'owner'}}]}],can_admins_bypass:false,
    deployment_branch_policy:{custom_branch_policies:true,protected_branches:false}};
const ownerPolicies={total_count:1,branch_policies:[{id:98,name:'main'}]};
const server={ruleset:structuredClone(ownerRule),environment:structuredClone(ownerEnv),
    policies:structuredClone(ownerPolicies),permission:'admin'};
const ownerCall=path=>{
    if (path.endsWith('/collaborators/owner/permission')) return {permission:server.permission};
    if (path.endsWith('/environments/release-promotion')) return server.environment;
    if (path.endsWith('/environments/release-promotion/deployment-branch-policies?per_page=100'))
        return server.policies;
    if (path.endsWith('/rulesets?per_page=100')) return [{id:24808772,name:server.ruleset.name,
        enforcement:server.ruleset.enforcement}];
    if (path.endsWith('/rulesets/24808772')) return server.ruleset;
    if (path.endsWith('/rules/branches/main')) return server.ruleset.rules;
    assert.fail('Unexpected owner-only fixture GET '+path);
};
const ownerContext={GITHUB_EVENT_NAME:'workflow_dispatch',GITHUB_REF:'refs/heads/main',GITHUB_ACTOR:'owner'};
assert.equal(requireOwnerDispatch(ownerRepo,ownerCall,ownerContext),'owner');
requireOwnerEnvironment(ownerCall,ownerRepo,'release-promotion');
requireOwnerRuleset(ownerCall,ownerRepo,'main',ownerRule.name,'Release qualification');
assert.throws(()=>requireOwnerDispatch(ownerRepo,ownerCall,{...ownerContext,GITHUB_ACTOR:'intruder'}),/BLOCKED/);
assert.throws(()=>requireOwnerDispatch(ownerRepo,ownerCall,{...ownerContext,GITHUB_REF:'refs/heads/develop'}),/BLOCKED/);
server.permission='read';
assert.throws(()=>requireOwnerDispatch(ownerRepo,ownerCall,ownerContext),/BLOCKED/);
server.permission='admin';
server.environment.can_admins_bypass=true;
assert.throws(()=>requireOwnerEnvironment(ownerCall,ownerRepo,'release-promotion'),/BLOCKED/);
server.environment.can_admins_bypass=false;
server.environment.protection_rules[0].prevent_self_review=true;
assert.throws(()=>requireOwnerEnvironment(ownerCall,ownerRepo,'release-promotion'),/BLOCKED/);
server.environment.protection_rules[0].prevent_self_review=false;
server.environment.protection_rules[0].reviewers[0].reviewer.login='other';
assert.throws(()=>requireOwnerEnvironment(ownerCall,ownerRepo,'release-promotion'),/BLOCKED/);
server.environment=structuredClone(ownerEnv);
server.policies.branch_policies[0].name='develop';
assert.throws(()=>requireOwnerEnvironment(ownerCall,ownerRepo,'release-promotion'),/BLOCKED/);
server.policies=structuredClone(ownerPolicies);
server.policies.branch_policies[0].type='tag';
assert.throws(()=>requireOwnerEnvironment(ownerCall,ownerRepo,'release-promotion'),/BLOCKED/);
server.policies=structuredClone(ownerPolicies);
for (const mutate of [
    state=>{state.enforcement='disabled';},
    state=>{state.bypass_actors=[{actor_id:1,actor_type:'RepositoryRole',bypass_mode:'always'}];},
    state=>{state.rules.find(rule=>rule.type==='pull_request').parameters.require_last_push_approval=true;},
    state=>{state.rules.find(rule=>rule.type==='required_status_checks').parameters.required_status_checks=[];},
    state=>{state.rules.push({type:'required_linear_history'});},
    state=>{state.rules.push(structuredClone(state.rules.find(rule=>rule.type==='required_status_checks')));},
    state=>{state.conditions.ref_name.include=['refs/heads/main','refs/heads/develop'];},
]) {
    server.ruleset=structuredClone(ownerRule);
    mutate(server.ruleset);
    assert.throws(()=>requireOwnerRuleset(ownerCall,ownerRepo,'main',ownerRule.name,'Release qualification'),
        /BLOCKED/);
}
server.ruleset=structuredClone(ownerRule);
delete server.ruleset.bypass_actors;
assert.throws(()=>requireOwnerRuleset(ownerCall,ownerRepo,'main',ownerRule.name,'Release qualification'),
    /BLOCKED/,'GitHub REST bypass-actor redaction must fail closed.');
server.ruleset=structuredClone(ownerRule);
const botPr={number:7,user:{login:'github-actions[bot]'},head:{sha:candidate,
    repo:{full_name:ownerRepo}},base:{ref:'main'},created_at:'2026-10-07T15:00:00Z',
merged_at:'2026-10-08T00:00:00Z'};
requireBotPullRequest(botPr,ownerRepo,candidate,'main');
assert.throws(()=>requireBotPullRequest({...botPr,user:{login:'owner'}},ownerRepo,candidate,'main'),/BLOCKED/);
assert.throws(()=>requireBotPullRequest(botPr,ownerRepo,'c'.repeat(40),'main'),/BLOCKED/);
const ownerReview={id:17,user:{login:'owner'},state:'APPROVED',commit_id:candidate,
    submitted_at:'2026-10-07T18:00:00Z'};
assert.equal(effectiveOwnerReview([ownerReview],botPr,'owner',candidate)?.id,17);
assert.equal(effectiveOwnerReview([{...ownerReview,commit_id:'b'.repeat(40)}],botPr,'owner',candidate),null);
assert.equal(effectiveOwnerReview([ownerReview,{...ownerReview,id:18,state:'DISMISSED',
    submitted_at:'2026-10-07T19:00:00Z'}],botPr,'owner',candidate),null);
assert.equal(effectiveOwnerReview([{...ownerReview,user:{login:'github-actions[bot]'}}],botPr,'owner',candidate),null);
assert.equal(effectiveOwnerReview([ownerReview],{...botPr,user:{login:'owner'}},'owner',candidate),null);
assert.equal(effectiveOwnerReview([ownerReview],{...botPr,merged_at:null},'owner',candidate),null);

process.stdout.write('PASS hosted release identity, owner approval, red evidence, CI-first and branch safety contracts\n');

const checkInventory=fakeApi('repos/'+record.repository+'/commits/'+candidate+'/check-runs?per_page=100&filter=all');
requireQualificationChecks(()=>checkInventory,record.repository,request,record.run_attempt);
for (const patch of [{conclusion:'failure'},{conclusion:'skipped'},{conclusion:'cancelled'},{conclusion:null},
    {status:'in_progress'},{head_sha:'0'.repeat(40)},
    {app:{id:1}},{external_id:''},{external_id:'release:124:1:'+candidate},
    {external_id:'release:123:2:'+candidate},{external_id:'candidate:123:1:'+candidate},
    {external_id:'release:123:9007199254740992:'+candidate}]) {
    const listing=structuredClone(checkInventory);Object.assign(listing.check_runs[0],patch);
    assert.throws(()=>requireQualificationChecks(()=>listing,record.repository,request,record.run_attempt),/qualification check/);
}
assert.throws(()=>requireQualificationChecks(()=>({total_count:100,check_runs:[]}),record.repository,request,record.run_attempt),/incomplete/);
assert.throws(()=>requireQualificationChecks(()=>({...checkInventory,total_count:3}),record.repository,request,record.run_attempt),/incomplete/);
for (const original of checkInventory.check_runs) {
    const independent={...original,external_id:'unrelated-pr-workflow',conclusion:'failure'};
    const listing={total_count:3,check_runs:[independent,...checkInventory.check_runs]};
    requireQualificationChecks(path=>{
        assert.ok(path.endsWith('&filter=all'),'All check attempts must remain visible for duplicate detection.');
        return listing;
    },record.repository,request,record.run_attempt);
    listing.check_runs=listing.check_runs.filter(check=>check!==original);
    listing.total_count=2;
    assert.throws(()=>requireQualificationChecks(()=>listing,record.repository,request,record.run_attempt),/qualification check/,
        'An independent green or red PR check cannot replace the required release identity.');
    for (const duplicate of [original,{...original,conclusion:'failure'},{...original,app:{id:1}}]) {
        assert.throws(()=>requireQualificationChecks(()=>({total_count:3,check_runs:[...checkInventory.check_runs,duplicate]}),
            record.repository,request,record.run_attempt),/qualification check/,'Duplicate exact release identities fail closed.');
    }
}
assert.throws(()=>requireQualificationChecks(()=>{throw new Error('CI unavailable');},record.repository,request,record.run_attempt),/CI unavailable/);
