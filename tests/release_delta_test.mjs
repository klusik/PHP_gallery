/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/release_delta_test.mjs
 * Module Type: Release Delta Regression
 * Purpose: Reject unowned A-to-B release changes and unbound BASIC evidence using inert Git data.
 * Responsibilities:
 *   - Preserve the requested version marker, notes, manuals and immutable origin allowance
 *   - Refuse runtime changes, mode/deletion/rename deltas and incomplete BASIC results
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {parseReleaseRawDelta,requireReleaseOwnedChange,collectReleaseDelta,bindBasicReleaseDelta} from '../.github/scripts/release-delta.mjs';

const a='a'.repeat(40),b='b'.repeat(40),treeA='c'.repeat(40),treeB='d'.repeat(40),zero='0'.repeat(40);
const oldBootstrap="<?php\nconst CMS_VERSION = '0.127';\nstart();\n";
const newBootstrap=oldBootstrap.replace("'0.127'","'0.128'");
const raw=':100644 100644 '+a+' '+b+' M\0app/bootstrap.php\0'
    +':100644 100644 '+a+' '+b+' M\0PATCH_NOTES.md\0'
    +':000000 100644 '+zero+' '+b+' A\0.github/release-origins/v_0.128.json\0';
const reader=args=>{
    const key=args.join('|');
    const fixtures={
        'rev-parse|HEAD':b+'\n','status|--porcelain=v1|--untracked-files=all':'',
        ['merge-base|'+a+'|'+b]:a+'\n',['rev-list|--merges|'+a+'..'+b]:'',
        ['diff|--raw|--no-abbrev|--no-renames|-z|'+a+'|'+b]:raw,
        ['show|'+a+':app/bootstrap.php']:oldBootstrap,['show|'+b+':app/bootstrap.php']:newBootstrap,
        ['rev-parse|'+a+'^{tree}']:treeA+'\n',['rev-parse|'+b+'^{tree}']:treeB+'\n',
    };
    assert.ok(Object.hasOwn(fixtures,key),'Unexpected Git read: '+key);
    return fixtures[key];
};
const evidence=collectReleaseDelta(a,b,'0.128',reader);
assert.equal(evidence.changes.length,3);
assert.equal(evidence.base_tree,treeA);
assert.equal(evidence.candidate_tree,treeB);
const report={schema_version:1,profile:'basic',status:'PASS',source:{head_sha:b,comparison_base:a,worktree_dirty:false},
    tasks:['php-lint-changed','js-lint-changed','source-documentation-changed'].map(id=>({id,status:'PASS',counts:{failed:0,blocked:0}}))};
assert.equal(bindBasicReleaseDelta(evidence,JSON.stringify(report)).successful,true);
assert.match(bindBasicReleaseDelta(evidence,JSON.stringify(report)).basic_report_sha256,/^sha256:[a-f0-9]{64}$/);
assert.deepEqual(parseReleaseRawDelta(''),[]);
for(const malformed of [raw.slice(0,-1),raw+raw,raw.replace(' M\0',' R100\0'),raw.replace(a,'x'.repeat(40))]) {
    assert.throws(()=>parseReleaseRawDelta(malformed),/BLOCKED/);
}
const marker=evidence.changes[0];
for(const changed of [
    {...marker,path:'app/services/auth.php'}, {...marker,status:'D'}, {...marker,new_mode:'100755'},
    {...marker,new_mode:'120000'}, {...marker,status:'A',old_mode:'000000',old_blob:zero},
    {...marker,path:'.github/workflows/release-qualification.yml'},
    {...marker,path:'.github/release-origins/v_0.128.json'},
]) assert.throws(()=>requireReleaseOwnedChange(changed,'0.128',oldBootstrap,newBootstrap),/BLOCKED/);
assert.throws(()=>requireReleaseOwnedChange(marker,'0.128',oldBootstrap,newBootstrap+'evil();\n'),/BLOCKED/);
assert.throws(()=>requireReleaseOwnedChange(marker,'0.128',oldBootstrap,newBootstrap.replace('0.128','0.129')),/BLOCKED/);
assert.throws(()=>requireReleaseOwnedChange(marker,'0.128',oldBootstrap,newBootstrap.replace('start();','stop();')),/BLOCKED/);
assert.throws(()=>requireReleaseOwnedChange(marker,'0.128',oldBootstrap,newBootstrap.replace('\n','\r\n')),/BLOCKED/);
assert.throws(()=>collectReleaseDelta(a,b,'0.128',args=>args.join('|')==='merge-base|'+a+'|'+b?treeA:reader(args)),/BLOCKED/);
assert.throws(()=>collectReleaseDelta(a,b,'0.128',args=>args[0]==='status'?' M source.php\n':reader(args)),/BLOCKED/);
assert.throws(()=>collectReleaseDelta(a,b,'01.128',reader),/BLOCKED/);
for(const mutation of [
    value=>{value.profile='full';}, value=>{value.status='FAIL';}, value=>{value.source.comparison_base=treeA;},
    value=>{value.source.head_sha=treeB;}, value=>{value.source.worktree_dirty=true;},
    value=>{value.tasks.pop();}, value=>{value.tasks.push(value.tasks[0]);},
    value=>{value.tasks[0].status='SKIP';}, value=>{value.tasks[0].counts.failed=1;},
    value=>{value.tasks[0].id='php-lint';},
]) {
    const bad=structuredClone(report);mutation(bad);
    assert.throws(()=>bindBasicReleaseDelta(evidence,JSON.stringify(bad)),/BLOCKED/);
}
process.stdout.write('PASS complete owned release delta and exact BASIC A-to-B report binding\n');
