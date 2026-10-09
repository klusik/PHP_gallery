/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/linear_release_fixture.mjs
 * Module Type: Release Test Fixture
 * Purpose: Supply inert SHA-bound publication, owner, linear content and CI transports.
 * Responsibilities:
 *   - Keep all mutable test state isolated from real GitHub and Git refs
 *   - Model full trees, parallel history, review and every required matrix owner
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createHash} from 'node:crypto';

/** Build a complete independently mutable linear-release server and Git fixture.
 * @returns {{repository:string,record:Record<string,string|boolean>,state:Record<string,unknown>,evidence:Record<string,string|boolean>,reconciliation:Record<string,unknown>,api:function(string,string=,object=):object,git:function(string,string[]):string,context:Record<string,string>}} Isolated source and server records.
 */
export function linearFixture() {
    const repository='fixture/gallery';
    const sha = value=>value.repeat(40);
    const selected=sha('b'),candidate=sha('c'),published=sha('a'),prior=sha('d'),develop=sha('e'),result=sha('f');
    const tree=sha('1'),linearTree=sha('2'),origin=sha('3'),blob=sha('4'),priorCandidate=sha('5');
    const state={developSha:develop,mainSha:published,resultSha:result,releaseSha:candidate,
        protected:true,published:true,changed:[],conflicts:[],parents:[result,develop],
        expectedTree:linearTree,resultTree:linearTree,ci:true,redJob:null,ownerRun:true,
        movedTag:false,openPr:false,openBasePr:false,active:false,writes:[],gitWrites:[],leaseFails:false,
        lostHistory:false,newMerges:false,race:false,proposal:null};
    const record={repository,version:'1.2.3',branch:'release/v_1.2.3',run_id:'123',run_attempt:'1',
        candidate_sha:candidate,source_base:priorCandidate,origin_commit_sha:origin,origin_blob_sha:blob,
        selected_develop_sha:selected,initial_main_sha:prior,previous_stable_tag:'v_1.2.2',
        previous_stable_sha:prior,previous_tag_object_sha:prior,previous_tag_object_type:'commit',
        previous_release_candidate_sha:priorCandidate,previous_tree_sha:sha('6'),predecessor_kind:'qualified',
        ready:true,automated_result:'success',override:false,final_main_sha:published,final_tree:tree};
    const proof={schema_version:1,repository,state:'RECONCILED_EQUIVALENT',candidate_sha:candidate,
        published_main_sha:published,selected_develop_sha:selected,develop_base_sha:develop,result_sha:result,
        expected_tree:linearTree,result_tree:linearTree,resolutions:[],owner_run_id:'900',owner_run_attempt:'1',regenerated_paths:[],
        qualification_url:'https://github.com/'+repository+'/actions/runs/1234',
        release_patch_sha256:createHash('sha256').update(selected+'..'+candidate).digest('hex'),
        reconciliation_patch_sha256:createHash('sha256').update(develop+'..'+result).digest('hex')};
    const ruleset=Object.assign(JSON.parse(readFileSync(new URL('../../.github/develop-reconciliation-ruleset.example.json',import.meta.url),'utf8')),
        {id:24808873,source:repository,current_user_can_bypass:'never'});
    const mandatory=['Read-only generated-state and source preflight','Positive production package (ubuntu-24.04)',
        'Positive production package (windows-2025)','Positive production package (macos-latest)',
        'PHP 8.3 workflows (mysql:8.4, Unicode)','PHP 8.3 workflows (mariadb:10.11, Unicode)',
        'PHP 8.5 workflows (mariadb:11.4, Unicode)','PHP 8.1 source (unicode)','PHP 8.5 source (ascii)',
        'Required Chromium fixtures','Complete required CI matrix'];
    const api=(path,method='GET',payload=null)=>{
        if (method!=='GET') state.writes.push({path,method,payload});
        if (path.endsWith('/git/ref/tags/v_1.2.3')) return {object:{type:'commit',sha:state.movedTag ? develop : published}};
        if (path.endsWith('/releases/tags/v_1.2.3')) return {tag_name:'v_1.2.3',draft:!state.published,
            prerelease:false,assets:[{name:'release-evidence.json'}]};
        if (path.endsWith('/commits/'+published)) return {sha:published,commit:{tree:{sha:tree}},parents:[{sha:prior},{sha:candidate}]};
        if (path.endsWith('/commits/'+candidate)) return {sha:candidate,commit:{tree:{sha:tree}}};
        if (path.endsWith('/commits/'+develop)) return {sha:develop,commit:{tree:{sha:linearTree}}};
        if (path.endsWith('/git/ref/heads/main')) return {object:{sha:state.mainSha}};
        if (path.endsWith('/git/ref/heads/develop')) return {object:{sha:state.race ? selected : state.developSha}};
        if (path.includes('/git/matching-refs/heads/')) {
            const branch=path.split('/git/matching-refs/heads/')[1];
            const current=branch===record.branch ? state.releaseSha : state.proposal;
            return current ? [{ref:'refs/heads/'+branch,object:{type:'commit',sha:current}}] : [];
        }
        if (path.includes('/compare/')) {
            const pair=path.split('/compare/')[1];
            const known=[selected+'...'+develop,selected+'...'+candidate,develop+'...'+result,
                result+'...'+state.developSha,published+'...'+state.mainSha];
            return {behind_by:state.lostHistory ? 1 : known.includes(pair)?0:1};
        }
        if (path.endsWith('/rulesets')) return [{id:24808873,name:ruleset.name,enforcement:state.protected?'active':'disabled'}];
        if (path.endsWith('/rulesets/24808873')) return {...ruleset,enforcement:state.protected?'active':'disabled'};
        if (path.endsWith('/rules/branches/develop')) return ruleset.rules;
        if (path.includes('/check-runs?')) return {total_count:2,check_runs:['Candidate qualification','Complete required CI matrix'].map(name=>({
            name,head_sha:path.split('/commits/')[1].split('/')[0],app:{id:15368},status:'completed',
            external_id:'candidate:1234:1:'+path.split('/commits/')[1].split('/')[0],
            conclusion:state.ci?'success':'failure',details_url:'https://github.com/'+repository+'/runs/114046888632'}))};
        if (path.endsWith('/actions/runs/900/attempts/1')) return {path:'.github/workflows/release-reconciliation.yml',
            event:'workflow_dispatch',head_branch:'main',run_attempt:1,actor:{login:'fixture'},status:'completed',
            conclusion:state.ownerRun?'success':'failure'};
        if (path.endsWith('/actions/runs/1234')) return {id:1234,path:'.github/workflows/candidate-preparation.yml',
            event:'push',head_branch:'feature/reconcile',status:'completed',conclusion:state.ci?'success':'failure',run_attempt:1};
        if (path.includes('/actions/runs/1234/attempts/1/jobs?')) return {total_count:mandatory.length,
            jobs:mandatory.map(name=>({name:'Full CI / '+name,status:'completed',conclusion:state.redJob===name?'failure':'success'}))};
        if (path.includes('/pulls?state=open&head=')) return state.openPr?[{number:7}]:[];
        if (path.includes('/pulls?state=open&base=')) return state.openBasePr?[{number:8}]:[];
        if (path.includes('/actions/workflows/') && path.includes('/runs?')) return {total_count:state.active?1:0,
            workflow_runs:state.active?[{head_branch:record.branch}]:[]};
        if (path.includes('/collaborators/fixture/permission')) return {permission:'admin'};
        if (path.includes('/deployment-branch-policies?')) return {total_count:1,branch_policies:[{name:'main',type:'branch'}]};
        if (path.includes('/environments/')) return {protection_rules:[{type:'required_reviewers',prevent_self_review:false,
            reviewers:[{type:'User',reviewer:{login:'fixture'}}]}],can_admins_bypass:false,
            deployment_branch_policy:{custom_branch_policies:true,protected_branches:false}};
        if (path.endsWith('/dispatches') && method==='POST') return {};
        assert.fail('Unexpected fixture API '+method+' '+path);
    };
    const git=(executable,args)=>{
        if (executable==='gh') {state.gitWrites.push({executable,args});return '';}
        assert.equal(executable,'git');
        if (args[0]==='merge-tree') return state.expectedTree+'\0'+state.conflicts.join('\0')+(state.conflicts.length?'\0':'')+'\0';
        if (args[0]==='rev-list' && args[1]==='--parents') return state.parents.join(' ');
        if (args[0]==='rev-list' && args[1]==='--merges') return state.newMerges?published:'';
        if (args[0]==='merge-base') return state.lostHistory?develop:selected;
        if (args[0]==='rev-parse') return state.resultTree;
        if (args[0]==='diff' && args.includes('--name-only')) return state.changed.map(path=>path+'\0').join('');
        if (args[0]==='diff') return args.at(-2)+'..'+args.at(-1);
        if (args[0]==='ls-tree') return args.at(-1)+'\0';
        if (args[0]==='commit-tree') return result;
        if (args[0]==='config') return '';
        if (args[0]==='push') {
            state.gitWrites.push({executable,args});
            if (state.leaseFails) throw new Error('remote ref lease failed');
            if (args.at(-1)===':refs/heads/'+record.branch) state.releaseSha=null;
            else {assert.ok(args.at(-1).endsWith(':refs/heads/feature/reconcile-v_1.2.3-'+develop.slice(0,12)));state.proposal=result;}
            return '';
        }
        assert.fail('Unexpected fixture Git '+args.join(' '));
    };
    return {repository,record,state,evidence:record,reconciliation:proof,api,git,
        context:{GITHUB_EVENT_NAME:'workflow_dispatch',GITHUB_REF:'refs/heads/main',GITHUB_ACTOR:'fixture'}};
}
