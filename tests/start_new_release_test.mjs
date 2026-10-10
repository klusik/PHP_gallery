/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/start_new_release_test.mjs
 * Module Type: Regression Test
 * Purpose: Exercise maintainer-pushed immutable origins against real isolated Git objects.
 * Responsibilities:
 *   - Reject duplicate immutable tags and conflicting or raced branch updates
 *   - Preserve the first origin commit on retries, including selected develop identity
 *   - Reject unauthorized pushes and preserve historic immutable origins
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {startNewRelease} from '../.github/scripts/start-new-release.mjs';
import {lifecycleFixture} from './support/release_lifecycle_fixture.mjs';
for (const version of ['1.3','1.2.1']) {
    const f=lifecycleFixture(version);
    try {
        const request={version,selected:f.selected};
        const initialized=startNewRelease(request,f.repository,f);
        assert.equal(initialized.state,'INITIALIZED');
        assert.equal(f.git('git',['rev-list','--parents','-n','1',initialized.origin_sha]),initialized.origin_sha+' '+f.selected);
        const origin=f.git('git',['show',initialized.origin_sha+':.github/release-origins/v_'+version+'.json']);
        assert.equal(JSON.parse(origin).initial_main_sha,f.previousMain);
        assert.equal(startNewRelease(request,f.repository,f).origin_sha,initialized.origin_sha);
        const record=f.prepare(initialized);
        const count=f.state.writes.length;
        assert.equal(startNewRelease(request,f.repository,f).candidate_sha,record.candidate_sha);
        assert.equal(f.state.writes.length,count,'Reruns reuse unchanged origin and Q without new commits.');
        assert.ok(!f.state.writes.some(write=>write.path.endsWith('/dispatches')),'Same run owns preparation.');
        assert.ok(f.state.writes.every(write=>!write.path.includes('/heads/main') && !write.path.includes('/heads/develop')));
        f.context.GITHUB_ACTOR='intruder';f.state.permission='read';
        assert.throws(()=>startNewRelease(request,f.repository,f),/maintainer/);
        assert.equal(f.state.writes.length,count);
    } finally {f.cleanup();}
}
for (const scenario of ['existing-tag','stale-event','main-drift','unretained-source']) {
    const f=lifecycleFixture();
    try {
        const request={version:'1.2.1',selected:f.selected};
        if (scenario==='existing-tag') f.git('git',['push','origin',f.previousMain+':refs/tags/v_1.2.1']);
        if (scenario==='stale-event' || scenario==='main-drift') {
            const parent=scenario==='main-drift'?f.previousMain:f.selected;
            const changed=f.git('git',['commit-tree',f.git('git',['rev-parse',parent+'^{tree}']),'-p',parent,'-m','Concurrent ref']);
            f.git('git',['push','origin',changed+':refs/heads/'+(scenario==='main-drift'?'main':'release/v_1.2.1')]);
        }
        if (scenario==='unretained-source') {
            const changed=f.git('git',['commit-tree',f.git('git',['rev-parse',f.selected+'^{tree}']),'-p',f.selected,'-m','Release-only source']);
            f.git('git',['push','origin',changed+':refs/heads/release/v_1.2.1']);
            request.selected=changed;f.context.GITHUB_SHA=changed;
        }
        assert.throws(()=>startNewRelease(request,f.repository,f),/BLOCKED/);
        assert.equal(f.state.writes.length,0,'Invalid initial refs never create origin objects or publish a commit.');
    } finally {f.cleanup();}
}
for (const version of ['01.3','1','1.2.3.4','1.2;command']) {
    assert.throws(()=>startNewRelease({version,selected:'a'.repeat(40)},'fixture/gallery',{}),/BLOCKED/);
}
process.stdout.write('PASS pushed X.Y and X.Y.Z origins, exact parent, real non-force push and idempotent same-run preparation\n');
