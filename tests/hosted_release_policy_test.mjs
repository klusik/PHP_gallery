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
import {validateRequest,verifyQualification,verifyFinalContent,promote,publish} from '../.github/scripts/release-promotion.mjs';

const candidate = 'a'.repeat(40);
const request = {runId:'123',candidate,branch:'release/v_1.2.3',mode:'plan',override:false,reason:'',acceptedFailures:[],manualReview:''};
const run = {path:'.github/workflows/release-qualification.yml',event:'push',head_branch:request.branch,status:'completed',conclusion:'success',run_attempt:1};
const record = {repository:'owner/gallery',branch:request.branch,candidate_sha:candidate,source_base:'b'.repeat(40),version:'1.2.3',run_id:'123',run_attempt:'1',ready:true};
const owners = ['Prepare release candidate','Read-only generated-state and source preflight',
    'Positive production package (ubuntu-24.04)','Positive production package (windows-2025)','Positive production package (macos-latest)',
    'PHP 8.3 workflows (mysql:8.4, Unicode)','PHP 8.3 workflows (mariadb:10.11, Unicode)','PHP 8.5 workflows (mariadb:11.4, Unicode)',
    'PHP 8.1 source (unicode)','PHP 8.5 source (ascii)','Required Chromium fixtures','Authoritative release audit','Complete required CI matrix','Release qualification gate'];
const jobs = owners.map(name => ({name,status:'completed',conclusion:'success',html_url:'https://github.com/owner/gallery/actions/runs/123'}));
assert.equal(validateRequest(request),'1.2.3');
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
// Inert publication must never append simulated evidence to a real Actions step.
delete process.env.GITHUB_STEP_SUMMARY;
delete process.env.GITHUB_OUTPUT;
process.env.RELEASE_ASSETS = directory;
process.env.GITHUB_ACTOR = 'fixture-maintainer';
let tagSha = null;
let releaseState = null;
let interruptUpload = true;
let unexpectedMain = false;
let changedWinapp = false;
const writes = [];
const mergeSha = 'c'.repeat(40);
const tree = 'd'.repeat(40);
const releasePr = {number:7,node_id:'fixture-pr',state:'closed',merged_at:'2026-10-08T00:00:00Z',
    merge_commit_sha:mergeSha,head:{sha:candidate,repo:{full_name:record.repository}},base:{ref:'main'},html_url:'https://github.com/owner/gallery/pull/7'};
const fakeApi = (path,method = 'GET',payload = null) => {
    if (method !== 'GET') writes.push({path,method,payload});
    if (path.includes('/pulls?')) return [structuredClone(releasePr)];
    if (path.endsWith('/commits/main')) return {sha:mergeSha,commit:{tree:{sha:unexpectedMain ? 'e'.repeat(40) : tree}}};
    if (path.endsWith('/commits/' + candidate)) return {sha:candidate,commit:{tree:{sha:tree}}};
    if (path.includes('/compare/')) return {behind_by:0,total_commits:1,files:changedWinapp ? [{filename:'winapp/gallery_uploader/self_update.py'}] : []};
    if (path.includes('/git/matching-refs/')) return tagSha ? [{ref:'refs/tags/v_1.2.3',object:{type:'commit',sha:tagSha}}] : [];
    if (path.endsWith('/git/refs') && method === 'POST') { tagSha = payload.sha; return {}; }
    if (path.endsWith('/git/ref/heads/main')) return {object:{sha:mergeSha}};
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
const adapters = {api:fakeApi,command:fakeCommand};
const publishRequest = {...request,mode:'publish',manualReview:'Independent browser acceptance fixture'};
try {
    for (const name of ['production.zip','core-manifest.json','production-files.json','release-metadata.json','release-notes.md','SHA256SUMS','qualification-evidence.zip']) writeFileSync(join(directory,name),'fixture public data ' + name);
    const hashes = Object.fromEntries(readdirSync(directory).map(name => [name,createHash('sha256').update(readFileSync(join(directory,name))).digest('hex')]));
    writeFileSync(join(directory,'final-integrity.json'),JSON.stringify({candidate_sha:candidate,source_base:record.source_base,result:'PASS',hashes}));
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
    releasePr.state = 'open';
    releasePr.merged_at = null;
    await promote({...publishRequest,mode:'promote'},record.repository,[],adapters);
    assert.ok(writes.at(-1).graphql);
    await promote({...exception,mode:'promote',manualReview:'Independent override acceptance'},record.repository,failedJobs.filter(job => job.conclusion === 'failure'),adapters);
    const merge = writes.at(-1);
    assert.equal(merge.payload.sha,candidate);
    assert.match(merge.payload.commit_message,/Original red checks remain red/);
    assert.ok(!writes.some(write => write.path?.includes('/git/refs/heads/main')));
} finally {
    if (previousAssets === undefined) delete process.env.RELEASE_ASSETS; else process.env.RELEASE_ASSETS = previousAssets;
    if (previousActor === undefined) delete process.env.GITHUB_ACTOR; else process.env.GITHUB_ACTOR = previousActor;
    if (previousSummary === undefined) delete process.env.GITHUB_STEP_SUMMARY; else process.env.GITHUB_STEP_SUMMARY = previousSummary;
    if (previousOutput === undefined) delete process.env.GITHUB_OUTPUT; else process.env.GITHUB_OUTPUT = previousOutput;
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
const promotion = read('.github/workflows/release-promotion.yml');
assert.match(promotion,/environment: release-promotion/);
assert.match(promotion,/persist-credentials: false/);
assert.match(promotion,/refs\/heads\/main/);
assert.match(promotion,/RELEASE_MODE: plan/);
assert.match(promotion,/cancel-in-progress: false/);
assert.doesNotMatch(promotion,/pull_request_target|secrets: inherit|git push/);
const helper = read('.github/scripts/release-promotion.mjs');
assert.match(helper,/verifyFinalContent\(main.sha,pr.merge_commit_sha,main.commit.tree.sha,candidate.commit.tree.sha\)/);
assert.match(helper,/prevent_self_review/);
assert.match(helper,/can_admins_bypass !== false/);
assert.match(helper,/immutable tag collision/);
assert.doesNotMatch(helper,/--clobber|force:true|conclusion:'success'.*override/);
for (const suffix of ['','_CZ','_DE','_SV']) {
    const manual = read(`docs/PHP_Gallery_Manual${suffix}.tex`);
    assert.match(manual,/CI-first/);
    assert.match(manual,/release-promotion/);
    assert.doesNotMatch(manual,/Use \\codeword\{--profile=full\} for~complete|Pro úplné ověření před předáním použijte|Verwenden Sie \\codeword\{--profile=full\} für die vollständige Übergabeprüfung|Använd \\codeword\{--profile=full\} för fullständig överlämningskontroll/);
}
process.stdout.write('PASS hosted release identity, red evidence, CI-first and branch safety contracts\n');
