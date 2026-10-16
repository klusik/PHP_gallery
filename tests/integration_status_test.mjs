/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/integration_status_test.mjs
 * Module Type: Integration Status Regression Test
 * Purpose: Bind protected-branch status publication to exact verified FULL and actual Actions identity.
 * Responsibilities: Exercise missing status, immutable qualification identity and adverse provider metadata.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {publishIntegrationStatus,requireIntegrationStatus} from '../.github/scripts/develop-integration.mjs';

const repository='owner/gallery',candidate='a'.repeat(40),run='123';
let statuses=[];
const writes=[];
const call=(path,method='GET',payload=null)=>{
    if (method==='POST') {
        assert.equal(path,`repos/${repository}/statuses/${candidate}`);
        writes.push(payload);
        const status={...payload,id:456,creator:{login:'github-actions[bot]'}};
        statuses=[status];return status;
    }
    assert.equal(path,`repos/${repository}/commits/${candidate}/status?per_page=100&page=1`);
    // GitHub's combined response omits creator, unlike the POST and status-history responses.
    return {total_count:statuses.length,statuses:statuses.map(({creator,...status})=>status)};
};
assert.throws(()=>requireIntegrationStatus(repository,candidate,
    {id:456,targetUrl:`https://github.com/${repository}/actions/runs/${run}`},call),/BLOCKED/,
    'a Check-only qualification cannot stand in for the branch-rule commit status');
const retained=publishIntegrationStatus(repository,candidate,run,call);
requireIntegrationStatus(repository,candidate,retained,call);
assert.deepEqual(retained,{id:456,targetUrl:`https://github.com/${repository}/actions/runs/${run}`});
assert.equal(writes[0].context,'Complete required CI matrix');
assert.equal(writes[0].state,'success');
assert.equal(writes.length,1,'status publication is separate from source execution and never invokes FULL');
const original=statuses[0];
for (const altered of [[],[original,original],[{...original,id:457}],
    ...['failure','pending','error'].map(state=>[{...original,state}]),
    [{...original,target_url:'https://example.invalid/other-run'}],
    [{...original,context:'Another check'}]]) {
    statuses=altered;
    assert.throws(()=>requireIntegrationStatus(repository,candidate,retained,call),/BLOCKED/,
        'changed identity, state or required context cannot authorize a ref update');
}
for (const altered of [{...original,creator:{login:'owner'}},{...original,creator:undefined},
    {...original,state:'failure'},{...original,context:'Another check'},
    {...original,target_url:'https://example.invalid/other-run'}]) {
    assert.throws(()=>publishIntegrationStatus(repository,candidate,run,()=>altered),/BLOCKED/,
        'creation authenticates the Actions author and exact status before its immutable ID can authorize publication');
}
for (const [repo,sha,runId] of [['owner/gallery;other',candidate,run],[repository,'HEAD',run],
    [repository,candidate,'0']]) {
    assert.throws(()=>publishIntegrationStatus(repo,sha,runId,()=>assert.fail('invalid identities cannot publish')),/BLOCKED/);
}
assert.throws(()=>publishIntegrationStatus(repository,candidate,run,()=>({id:0})),/BLOCKED/);
const workflow=readFileSync(new URL('../.github/workflows/develop-integration.yml',import.meta.url),'utf8');
const publisher=workflow.slice(workflow.indexOf('  publish-develop:'));
assert.match(publisher,/statuses: write/,'only the privileged verified publication job requests status authority');
assert.ok(!workflow.slice(0,workflow.indexOf('  publish-develop:')).includes('statuses: write'));
process.stdout.write('PASS exact FULL status binding, Actions creator, stale/adverse metadata and scoped publication authority\n');
