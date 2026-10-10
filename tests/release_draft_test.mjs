/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/release_draft_test.mjs
 * Module Type: Regression Test
 * Purpose: Verify create-only draft staging after qualified merge/tag without production writes.
 * Responsibilities:
 *   - Reuse the exact Q patch notes and all existing completion security guards
 *   - Cover paginated drafts, published releases, conflicts and interruption recovery
 *   - Refuse publication, asset uploads and changes to manual draft edits
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {startNewRelease} from '../.github/scripts/start-new-release.mjs';
import {openQualifiedPullRequest,completeRelease,prepareDraftRelease} from '../.github/scripts/release-completion.mjs';
import {lifecycleFixture} from './support/release_lifecycle_fixture.mjs';

const fixture=lifecycleFixture();
try {
    const record=fixture.prepare(startNewRelease({version:'1.2.1',selected:fixture.selected},fixture.repository,fixture));
    const pr=openQualifiedPullRequest(record,fixture.repository,'## Version 1.2.1\nPrepared fixture notes',fixture);
    const tagged=completeRelease(record,fixture.repository,pr.number,fixture);
    assert.equal(tagged.state,'TAGGED');
    const repository=fixture.repository;
    const endpoint='repos/'+repository+'/releases';
    const notes='## Version 1.2.1\nPrepared fixture notes\n';
    const source=notes+'\n## Version 1.2\nOld notes\n';
    const releases=[];
    let writes=0;
    let loseResponse=false;
    let hashMismatch=false;
    let pagesRead=0;
    const server=(path,method='GET',payload=null)=>{
        if (path===endpoint && method==='POST') {
            writes++;
            assert.equal(payload.tag_name,'v_1.2.1');
            assert.equal(payload.target_commitish,tagged.main_sha);
            assert.equal(payload.name,'Version 1.2.1');
            assert.equal(payload.body,notes,'The existing release-notes.md section is reused byte for byte.');
            assert.equal(payload.draft,true);
            assert.equal(payload.prerelease,false);
            assert.equal(payload.generate_release_notes,false);
            const release={id:900+writes,tag_name:payload.tag_name,name:payload.name,body:payload.body,
                draft:true,prerelease:false,assets:[],html_url:'https://github.com/'+repository+'/releases/tag/untagged-abc'+writes};
            releases.push(release);
            if (loseResponse) {loseResponse=false;throw new Error('Simulated HTTP response loss after draft creation');}
            return structuredClone(release);
        }
        if (method==='GET' && path.startsWith(endpoint+'/') && /^[1-9]\d*$/.test(path.slice(endpoint.length+1))) {
            const found=releases.find(release=>String(release.id)===path.slice(endpoint.length+1));
            assert.ok(found,'Only known release IDs may be queried.');
            return structuredClone(found);
        }
        return fixture.api(path,method,payload);
    };
    const execute=(program,args,input=null)=>{
        if (program==='gh' && args[0]==='api' && args[1]===endpoint+'?per_page=100') {
            pagesRead++;
            assert.deepEqual(args.slice(-2),['--paginate','--slurp']);
            const pages=[];
            for (let offset=0;offset<releases.length;offset+=100) pages.push(structuredClone(releases.slice(offset,offset+100)));
            return JSON.stringify(pages.length?pages:[[]]);
        }
        return fixture.git(program,args,input);
    };
    const git=(program,args,input=null)=>{
        if (program==='git' && args[0]==='rev-parse' && args[1]===record.candidate_sha+':PATCH_NOTES.md') return 'f'.repeat(40);
        if (program==='git' && args[0]==='hash-object' && args[1]==='PATCH_NOTES.md') return hashMismatch?'e'.repeat(40):'f'.repeat(40);
        return fixture.git(program,args,input);
    };
    const adapters={...fixture,api:server,execute,git,readPatchNotes:()=>source};
    const initial=fixture.state.writes.length;
    hashMismatch=true;
    assert.throws(()=>prepareDraftRelease(record,repository,tagged,adapters),/does not exactly match qualified Q/);
    hashMismatch=false;
    assert.equal(writes,0,'Untrusted notes must never create a draft.');
    assert.throws(()=>prepareDraftRelease(record,repository,{...tagged,tag:'v_9.9'},adapters),/matching TAGGED/);
    assert.throws(()=>prepareDraftRelease(record,repository,{...tagged,main_sha:'9'.repeat(40)},adapters),/BLOCKED/);
    assert.equal(writes,0);
    const first=prepareDraftRelease(record,repository,tagged,adapters);
    assert.equal(first.status,'DRAFT_CREATED');
    assert.equal(first.release_id,901);
    assert.equal(first.url,'https://github.com/'+repository+'/releases/edit/untagged-abc1');
    assert.deepEqual(releases[0].assets,[]);
    assert.equal(fixture.state.writes.length,initial,'No merge or tag write occurs during draft staging.');
    const queried=pagesRead;
    releases[0].name='Manually edited title';
    releases[0].body='Manually edited description';
    releases[0].assets.push({name:'manual.pdf',size:1234});
    const repeated=prepareDraftRelease(record,repository,tagged,adapters);
    assert.equal(repeated.status,'DRAFT_ALREADY_EXISTS');
    assert.ok(pagesRead>queried);
    assert.equal(writes,1);
    assert.equal(releases[0].name,'Manually edited title');
    assert.equal(releases[0].body,'Manually edited description');
    assert.equal(releases[0].assets.length,1);
    releases[0].draft=false;
    assert.equal(prepareDraftRelease(record,repository,tagged,adapters).status,'ALREADY_PUBLISHED');
    assert.equal(writes,1,'A published release must never be changed.');
    releases[0].draft=true;
    releases[0].prerelease=true;
    assert.throws(()=>prepareDraftRelease(record,repository,tagged,adapters),/conflicting prerelease/);
    releases[0].prerelease=false;

    // More than 100 releases: the matching editable draft must be found on page two.
    for (let i=0;i<100;i++) releases.unshift({id:1000+i,tag_name:'v_0.'+i,name:'Version 0.'+i,
        draft:false,prerelease:false,assets:[],html_url:'https://github.com/'+repository+'/releases/tag/v_0.'+i});
    assert.equal(prepareDraftRelease(record,repository,tagged,adapters).status,'DRAFT_ALREADY_EXISTS');
    assert.equal(writes,1);
    releases.push({...releases.at(-1),id:2000});
    assert.throws(()=>prepareDraftRelease(record,repository,tagged,adapters),/duplicate tag identity/);
    releases.pop();
    releases[0].name='Version 1.2.1';
    assert.throws(()=>prepareDraftRelease(record,repository,tagged,adapters),/conflicting release version/);
    releases[0].name='Version 0.99';

    // Interrupted between the immutable tag and returned draft response: retry finds the draft.
    releases.splice(0);
    loseResponse=true;
    assert.throws(()=>prepareDraftRelease(record,repository,tagged,adapters),/Simulated HTTP response loss/);
    assert.equal(writes,2);
    assert.equal(prepareDraftRelease(record,repository,tagged,adapters).status,'DRAFT_ALREADY_EXISTS');
    assert.equal(writes,2,'Replaying after a lost response cannot create duplicate drafts.');

    // An advanced develop status is independent of already valid M/tag and may stage a draft.
    assert.equal(prepareDraftRelease(record,repository,{...tagged,synchronization:'BLOCKED_DEVELOP_ADVANCED'},adapters).status,'DRAFT_ALREADY_EXISTS');
    assert.equal(fixture.state.writes.length,initial);
    assert.ok(!fixture.state.writes.some(write=>write.path.includes('/releases')));
} finally {fixture.cleanup();}
process.stdout.write('PASS verified draft creation, exact Q notes, paginated idempotence, publish safety and interruption recovery\n');
