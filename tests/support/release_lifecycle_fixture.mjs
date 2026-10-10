/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/release_lifecycle_fixture.mjs
 * Module Type: Isolated Release Lifecycle Fixture
 * Purpose: Combine real disposable Git refs and objects with deterministic GitHub authorization responses.
 * Responsibilities:
 *   - Exercise normal pushes, immutable origin blobs, two-parent merges and tag creation
 *   - Simulate only hosted CI and review metadata, without contacting production GitHub
 *   - Retain all changes inside an owned temporary checkout and bare remote
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {mkdtempSync,mkdirSync,writeFileSync,readFileSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {spawnSync} from 'node:child_process';
import {verifyReleaseOrigin} from '../../.github/scripts/release-origin.mjs';

/** Gate-owned exact candidate and predecessor identities used by this real-Git fixture.
 * @typedef {import('../../.github/scripts/release-promotion.mjs').ReleaseRecord} FixtureRecord
 */
/** Literal recursive API values; validators independently narrow each server response.
 * @typedef {import('../../.github/scripts/release-promotion.mjs').JsonValue} FixtureJson
 */
/** Complete PR fields needed to bind list/detail, exact head, protected merge and human review.
 * @typedef {{number:number,node_id:string,user:{login:string},state:string,merged:boolean,auto_merge:null|{merge_method:string,enabled_by:{login:string}},
 * head:{ref:string,sha:string,repo:{full_name:string}},base:{ref:string,sha:string,repo:{full_name:string}},
 * mergeable:boolean,merge_commit_sha:string,merged_at:string|null,created_at:string,html_url:string,merged_by?:{login:string}} FixturePullRequest
 */
/** Explicit mutable test controls and complete retained run/PR/check collections.
 * @typedef {{writes:Array<{path:string,method:string,payload:FixtureJson|null}>,pr:FixturePullRequest|null,
 * checks:Array<{id:number,name:string,app:{id:number},head_sha:string,status:string,conclusion:string,external_id:string}>,
 * record:FixtureRecord|null,records:Record<string,FixtureRecord>,runId:number,review:boolean,ci:boolean,
 * tagFailOnce:boolean,wrongTree:boolean,policyVisible:boolean,permission:string,race:boolean,tagCreated:number,
 * prHistory?:Record<string,FixturePullRequest[]>}} LifecycleState
 */
/** Historical published predecessor fields read by the compatibility evidence validator.
 * @typedef {import('../../.github/scripts/release-promotion.mjs').PublicationEvidence} FixturePublicationEvidence
 */

/** Build a disposable real-Git release graph and bounded synthetic hosting metadata.
 * @param {string} version Canonical next release version, defaulting to the patch form.
 * @returns {{repository:string,selected:string,previousMain:string,previousQ:string,state:LifecycleState,context:Record<string,string>,evidence:FixturePublicationEvidence,api:function(string,string=,(FixtureJson|null)=):FixtureJson,git:function(string,string[],(string|null)=):string,jobs:Array<{name:string,status:string,conclusion:string,html_url:string}>,predecessorRecord:FixtureRecord|null,predecessorJobs:import('../../.github/scripts/release-promotion.mjs').ReleaseJob[],prepare:function({candidate_sha:string}):FixtureRecord,cleanup:function():void,next:function(string):ReturnType<typeof lifecycleFixture>}} Fixture adapters, preparation, successor transition and owned cleanup.
 */
export function lifecycleFixture(version='1.2.1') {
    const directory=mkdtempSync(join(tmpdir(),'gallery-release-lifecycle-'));
    const checkout=join(directory,'checkout'),remote=join(directory,'remote.git');
    mkdirSync(checkout);
    const raw=(cwd,args,input=null)=>{
        const result=spawnSync('git',['-C',cwd,...args],{input,encoding:'utf8',timeout:10000});
        assert.equal(result.status,0,result.stderr || result.stdout);
        return args.includes('-z') || args[0]==='diff' ? result.stdout : result.stdout.trim();
    };
    const git=(...args)=>raw(checkout,args);
    raw(directory,['init','--bare','-q',remote]);
    git('init','-q');git('config','user.name','Fixture');git('config','user.email','fixture@example.test');
    git('remote','add','origin',remote);
    const save=(name,text)=>writeFileSync(join(checkout,name),text);
    const commit=message=>{git('add','-A');git('commit','-qm',message);return git('rev-parse','HEAD');};
    save('content.txt','old\n');const oldMain=commit('Original baseline');
    save('content.txt','previous release\n');const previousQ=commit('Previous Q');
    const previousTree=git('rev-parse',previousQ+'^{tree}');
    const previousMain=git('commit-tree',previousTree,'-p',oldMain,'-p',previousQ,'-m','Previous release M');
    git('tag','-a','v_1.2','-m','Annotated previous release',previousMain);
    git('update-ref','refs/heads/main',previousMain);git('switch','-qc','develop',previousQ);
    save('feature.txt','retained feature\n');const selected=commit('Single-parent feature');
    let branch='release/v_'+version;
    git('switch','-qc',branch);git('push','-q','origin','main','develop',branch,'--tags');
    const repository='fixture/gallery';
    const evidence={repository,version:'1.2',final_main_sha:previousMain,final_tree:previousTree,
        candidate_sha:previousQ,ready:true,automated_result:'success',override:false,run_id:'99'};
    const state={writes:[],pr:null,checks:[],record:null,records:{},runId:123,review:true,ci:true,tagFailOnce:false,wrongTree:false,
        policyVisible:true,permission:'admin',race:false,tagCreated:0};
    const ref=name=>raw(remote,['rev-parse',name]);
    const exists=name=>spawnSync('git',['-C',remote,'show-ref','--verify','--quiet',name]).status===0;
    const object=sha=>({sha,commit:{tree:{sha:git('rev-parse',sha+'^{tree}')}},
        parents:git('rev-list','--parents','-n','1',sha).split(/\s+/).slice(1).map(sha=>({sha}))});
    const rules=['main','develop'].map(target=>Object.assign(JSON.parse(readFileSync(new URL(target==='main'
        ? '../../.github/release-ruleset.example.json' : '../../.github/develop-reconciliation-ruleset.example.json',import.meta.url),'utf8')),
        {id:target==='main'?1:2,source:repository,current_user_can_bypass:'never'}));
    const owners=['Prepare release candidate','Read-only generated-state and source preflight',
        'Positive production package (ubuntu-24.04)','Positive production package (windows-2025)','Positive production package (macos-latest)',
        'PHP 8.3 workflows (mysql:8.4, Unicode)','PHP 8.3 workflows (mariadb:10.11, Unicode)','PHP 8.5 workflows (mariadb:11.4, Unicode)',
        'PHP 8.1 source (unicode)','PHP 8.5 source (ascii)','Required Chromium fixtures','Authoritative release audit',
        'Complete required CI matrix','Release qualification gate','Prepare manual release assets','Prepare Windows companion installer','Open qualified release PR'];
    const jobs=owners.map(name=>({name,status:'completed',conclusion:'success',html_url:'https://example.test/ci/123'}));
    const transport=(executable,args,input=null)=>{
        if (executable==='gh') return JSON.stringify([raw(remote,['for-each-ref','--format=%(refname)','refs/tags/v_*']).split('\n')
            .filter(Boolean).map(name=>({ref:name,object:{type:name==='refs/tags/v_1.2'?'tag':'commit',sha:ref(name)}}))]);
        assert.equal(executable,'git');
        return raw(checkout,args,input);
    };
    const call=(path,method='GET',payload=null)=>{
        if (method!=='GET') state.writes.push({path,method,payload});
        const tail=path.replace('repos/'+repository+'/','');
        if (/^collaborators\/[\w.-]+\/permission$/.test(tail)) return {permission:state.permission};
        if (tail==='rulesets?per_page=100') return rules.map(rule=>({id:rule.id,name:rule.name,enforcement:rule.enforcement}));
        if (/^rulesets\/[12]$/.test(tail)) {
            const rule=structuredClone(rules[Number(tail.at(-1))-1]);
            if (!state.policyVisible) delete rule.bypass_actors;
            return rule;
        }
        if (tail.startsWith('rules/branches/')) return rules[tail.endsWith('main')?0:1].rules;
        if (tail.startsWith('git/ref/heads/')) return {object:{type:'commit',sha:ref('refs/'+tail.slice('git/ref/'.length))}};
        if (tail.startsWith('git/matching-refs/')) {
            const name='refs/'+tail.slice('git/matching-refs/'.length);
            return exists(name)?[{ref:name,object:{type:'commit',sha:ref(name)}}]:[];
        }
        if (tail==='git/ref/tags/v_1.2') return {object:{type:'tag',sha:ref('refs/tags/v_1.2')}};
        if (tail.startsWith('git/ref/tags/')) return {object:{type:'commit',sha:ref('refs/'+tail.slice('git/ref/'.length))}};
        if (tail.startsWith('git/tags/')) return {sha:tail.slice('git/tags/'.length),object:{type:'commit',sha:previousMain}};
        if (tail==='releases/tags/v_1.2') return {tag_name:'v_1.2',draft:false,prerelease:false,assets:[{name:'release-evidence.json'}]};
        if (tail.startsWith('releases/tags/')) throw new Error('BLOCKED_API_NOT_FOUND: no manual GitHub Release yet.');
        if (tail.startsWith('compare/')) {
            const [left,right]=tail.slice('compare/'.length).split('...');
            return {behind_by:spawnSync('git',['-C',checkout,'merge-base','--is-ancestor',left,right]).status===0?0:1};
        }
        if (tail==='git/blobs' && method==='POST') return {sha:raw(checkout,['hash-object','-w','--stdin'],payload.content)};
        if (tail==='git/trees' && method==='POST') {
            git('read-tree',payload.base_tree);
            for (const entry of payload.tree) git('update-index','--add','--cacheinfo',entry.mode,entry.sha,entry.path);
            return {sha:git('write-tree')};
        }
        if (tail==='git/commits' && method==='POST') {
            const sha=git('commit-tree',payload.tree,'-p',payload.parents[0],'-m',payload.message);
            raw(remote,['fetch','-q',checkout,sha]);
            return {sha,parents:payload.parents.map(sha=>({sha}))};
        }
        if (tail.startsWith('git/commits/')) {const value=object(tail.slice('git/commits/'.length));return {...value,tree:value.commit.tree};}
        if (tail.startsWith('commits/') && tail.includes('/check-runs?')) {
            const sha=tail.split('/')[1];
            const record=Object.values(state.records).find(record=>record.candidate_sha===sha);
            const checks=record ? ['Release qualification','Complete required CI matrix'].map((name,i)=>({id:i+1,name,
                app:{id:15368},head_sha:sha,status:'completed',conclusion:'success',external_id:'release:'+record.run_id+':1:'+sha})) : state.checks;
            return {total_count:checks.length,check_runs:checks.map(check=>({...check,conclusion:state.ci?'success':'failure'}))};
        }
        if (tail.startsWith('commits/') && tail.includes('/pulls?')) {
            return state.prHistory?.[tail.split('/')[1]] ?? [];
        }
        if (tail.startsWith('commits/')) return object(tail.slice('commits/'.length)==='main'?ref('refs/heads/main'):tail.slice('commits/'.length));
        if (/^actions\/runs\/\d+$/.test(tail)) {
            const id=Number(tail.split('/').at(-1));const record=state.records[id];
            return {id,path:'.github/workflows/release-qualification.yml',event:'push',head_branch:record?.branch ?? branch,
                status:'completed',conclusion:state.ci?'success':'failure',run_attempt:1};
        }
        if (tail.startsWith('pulls?')) {
            if (!state.pr || tail.includes('state=closed') && !state.pr.merged) return [];
            const listed=structuredClone(state.pr);delete listed.merged_by;return [listed];
        }
        if (tail==='pulls' && method==='POST') {
            const head=ref('refs/heads/'+branch),base=ref('refs/heads/main');
            const preview=git('commit-tree',git('rev-parse',head+'^{tree}'),'-p',base,'-p',head,'-m','Test merge');
            state.pr={number:state.runId-116,node_id:'PR_fixture_'+state.runId,user:{login:'github-actions[bot]'},state:'open',merged:false,auto_merge:null,
                head:{ref:branch,sha:head,repo:{full_name:repository}},base:{ref:'main',sha:base,repo:{full_name:repository}},
                mergeable:true,merge_commit_sha:preview,merged_at:null,created_at:'2026-10-01T00:00:00Z',html_url:'https://example.test/pr/'+(state.runId-116)};
            return structuredClone(state.pr);
        }
        if (/^pulls\/\d+\/reviews\?per_page=100$/.test(tail)) {
            const number=Number(tail.split('/')[1]);
            const pr=state.pr?.number===number?state.pr:Object.values(state.prHistory ?? {}).flat().find(pr=>pr.number===number);
            return state.review?[{id:17,user:{login:'fixture'},state:'APPROVED',
                commit_id:pr.head.sha,submitted_at:'2026-10-02T00:00:00Z'}]:[];
        }
        if (/^pulls\/\d+\/merge$/.test(tail) && method==='PUT') {
            assert.equal(payload.merge_method,'merge');assert.equal(payload.sha,ref('refs/heads/'+branch));
            const tree=git('rev-parse',(state.wrongTree?previousQ:payload.sha)+'^{tree}');
            const merge=git('commit-tree',tree,'-p',ref('refs/heads/main'),'-p',payload.sha,'-m','Release merge');
            git('push','-q','origin',merge+':refs/heads/main');
            state.pr={...state.pr,state:'closed',merged:true,merge_commit_sha:merge,merged_at:'2026-10-03T00:00:00Z',merged_by:{login:'github-actions[bot]'}};
            state.prHistory ??= {};state.prHistory[merge]=[structuredClone(state.pr)];
            return {merged:true,sha:merge};
        }
        if (/^pulls\/\d+$/.test(tail)) {
            const number=Number(tail.split('/')[1]);
            return structuredClone(state.pr?.number===number?state.pr:Object.values(state.prHistory ?? {}).flat().find(pr=>pr.number===number));
        }
        if (tail==='git/refs' && method==='POST') {
            if (state.tagFailOnce) {state.tagFailOnce=false;throw new Error('simulated interruption before tag');}
            assert.equal(exists(payload.ref),false,'Tags are create-only');raw(remote,['update-ref',payload.ref,payload.sha]);
            state.tagCreated++;return {ref:payload.ref,object:{sha:payload.sha,type:'commit'}};
        }
        assert.fail('Unexpected fixture API '+method+' '+tail);
    };
    const prepare=result=>{
        git('checkout','-qf',result.candidate_sha);save('content.txt','new release '+version+'\n');const candidate=commit('Prepared Q');
        git('push','-q','origin',candidate+':refs/heads/'+branch);
        const origin=JSON.parse(git('show',candidate+':.github/release-origins/v_'+version+'.json'));
        const provenance=verifyReleaseOrigin(origin,version,candidate,repository,{api:call,git:transport,evidence,
            predecessorRecord:fixture.predecessorRecord,predecessorJobs:jobs});
        const record={...provenance,repository,version,branch,candidate_sha:candidate,run_id:String(state.runId),run_attempt:'1',
            ready:true,automated_result:'success',override:false,lifecycle_schema:2,winapp_changed:true};
        state.record=record;
        state.records[state.runId]=record;
        state.checks=['Release qualification','Complete required CI matrix'].map((name,i)=>({id:i+1,name,
            app:{id:15368},head_sha:candidate,status:'completed',conclusion:'success',external_id:'release:'+state.runId+':1:'+candidate}));
        return record;
    };
    const fixture={repository,selected,previousMain,previousQ,state,evidence,api:call,git:transport,jobs,prepare,
        predecessorRecord:null,predecessorJobs:jobs,
        context:{GITHUB_EVENT_NAME:'push',GITHUB_REF:'refs/heads/'+branch,GITHUB_SHA:selected,GITHUB_ACTOR:'fixture'},
        cleanup:()=>rmSync(directory,{recursive:true,force:true}),next:nextVersion=>{
            fixture.predecessorRecord=state.record;
            git('fetch','-q','origin','--tags');
            git('checkout','-qf','--detach',ref('refs/heads/develop'));
            save('successor.txt','next feature\n');const nextSelected=commit('Next linear feature');
            git('push','-q','origin',nextSelected+':refs/heads/develop');
            version=nextVersion;branch='release/v_'+version;state.runId++;
            git('push','-q','origin',nextSelected+':refs/heads/'+branch);
            fixture.selected=nextSelected;fixture.previousMain=ref('refs/heads/main');fixture.previousQ=state.record.candidate_sha;
            Object.assign(fixture.context,{GITHUB_REF:'refs/heads/'+branch,GITHUB_SHA:nextSelected});
            state.pr=null;
            return fixture;
        }};
    return fixture;
}
