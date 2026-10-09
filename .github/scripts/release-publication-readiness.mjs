/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-publication-readiness.mjs
 * Module Type: Hosted Publication Readiness
 * Purpose: Diagnose the real job token and reuse production validators before deployment approval.
 * Responsibilities:
 *   - Retain safe field presence, types and the original failing ruleset predicates
 *   - Prohibit all server writes while checking policy, qualification, assets and release conflicts
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {readFileSync,writeFileSync} from 'node:fs';
import {resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {api,command,readRequest,verifyWriteControls,verifyRecoveryPublication,preparePublicationPayload,verifyPublicationInventory,output} from './release-promotion.mjs';
import {PUBLICATION_RECOVERY,requirePublicationRecoveryDispatch,verifyRulesetEffectivePolicy} from './release-owner-authorization.mjs';

/** Recursive complete JSON report and API data.
 * @typedef {null|boolean|number|string|JsonValue[]|{[key:string]:JsonValue}} JsonValue
 */

/** Restrict the production API adapter to GET requests without payloads.
 * @param {string} path Literal GitHub repository API path.
 * @param {string} method Requested HTTP method; only GET is allowed.
 * @param {JsonValue|null} payload Request data; every non-null value is prohibited.
 * @returns {JsonValue} Complete read-only server response.
 */
export function readinessApi(path,method='GET',payload=null) {
    if (method!=='GET' || payload!==null || !(path==='repos/'+PUBLICATION_RECOVERY.repository || path.startsWith('repos/'+PUBLICATION_RECOVERY.repository+'/'))) {
        throw new Error('BLOCKED_READINESS_WRITE: readiness prohibits server mutations.');
    }
    return api(path);
}

/** Admit only production Git readers and paginated GET inventory commands.
 * @param {string} executable Production command name.
 * @param {string[]} args Literal argument vector.
 * @param {string|null} input Optional stdin; readiness never supplies input.
 * @returns {string} Complete read-only command output, or throws before any write.
 */
export function readinessCommand(executable,args,input=null) {
    const git=executable==='git' && ['show','log','rev-list','rev-parse','diff'].includes(args[0])
        && !args.some(arg=>arg.startsWith('--output'));
    const inventory=executable==='gh' && args[0]==='api' && args.length===4
        && args[1]==='repos/'+PUBLICATION_RECOVERY.repository+'/releases?per_page=100'
        && args[2]==='--paginate' && args[3]==='--slurp';
    if (input!==null || (!git && !inventory)) throw new Error('BLOCKED_READINESS_WRITE: readiness command is not a permitted reader.');
    return command(executable,args);
}

/** Retain relevant non-secret ruleset fields and each original compound failure predicate.
 * @param {JsonValue} installed Complete job-token-visible REST detail.
 * @returns {{fields:Record<string,{present:boolean,type:string,value:JsonValue}>,original_failing_predicates:string[]}} Safe field evidence; missing values are marked absent, never presumed empty.
 */
export function describeRuleset(installed) {
    const keys=['id','node_id','name','source','source_type','target','enforcement','created_at','updated_at',
        'bypass_actors','current_user_can_bypass','conditions','rules','_links'];
    const fields=Object.fromEntries(keys.map(key=>{
        const present=installed!==null && typeof installed==='object' && Object.hasOwn(installed,key);
        const value=present ? installed[key] : null;
        return [key,{present,type:!present ? 'absent' : value===null ? 'null' : Array.isArray(value) ? 'array' : typeof value,value}];
    }));
    const failures=[
        ['name',installed?.name!=='Reviewed release promotion to main'],
        ['enforcement',installed?.enforcement!=='active'],['target',installed?.target!=='branch'],
        ['source',installed?.source!==PUBLICATION_RECOVERY.repository],
        ['!Array.isArray(installed.bypass_actors)',!Array.isArray(installed?.bypass_actors)],
        ['installed.bypass_actors.length !== 0',Array.isArray(installed?.bypass_actors) && installed.bypass_actors.length!==0],
        ['current_user_can_bypass',installed?.current_user_can_bypass!==undefined && installed.current_user_can_bypass!=='never'],
        ['ref_name.include',JSON.stringify(installed?.conditions?.ref_name?.include)!=='["refs/heads/main"]'],
        ['ref_name.exclude',JSON.stringify(installed?.conditions?.ref_name?.exclude)!=='[]'],
    ].filter(([,failed])=>failed).map(([predicate])=>predicate);
    return {fields,original_failing_predicates:failures};
}

/** Run independent production readiness phases with an adapter that prohibits every server write.
 * @param {import('./release-promotion.mjs').ReleaseRequest} request Exact proposed publication.
 * @param {import('./release-promotion.mjs').ReleaseRecord} record Original qualification record.
 * @param {{api:function(string,string=,(JsonValue|null)=):JsonValue,command:function(string,string[],(string|null)=):string,context:Record<string,string|undefined>}} readers Read-only live or inert fixture transport.
 * @returns {{result:string,tooling_sha:string,checks:Record<string,{result:string,code:string,detail:JsonValue}>,missing_operations:string[]}} Complete evidence with explicit blockers; no deployment or publication authority.
 */
export function publicationReadiness(request,record,readers) {
    const repository=PUBLICATION_RECOVERY.repository;
    const adapters={...readers,record,readOnly:true};
    const checks={};
    const phase=(name,check)=>{
        try { checks[name]={result:'PASS',code:'PASS',detail:check() ?? null}; }
        catch (error) { checks[name]={result:'BLOCKED',code:/^BLOCKED[A-Z_]*\b/.exec(error.message)?.[0] ?? 'BLOCKED',detail:error.message}; }
    };
    phase('scope',()=>{
        if (!requirePublicationRecoveryDispatch(repository,readers.context,request,true)) throw new Error('BLOCKED_READINESS_SCOPE: exact owner recovery context required.');
        return {ref:readers.context.GITHUB_REF,event:readers.context.GITHUB_EVENT_NAME,actor:readers.context.GITHUB_ACTOR};
    });
    if (checks.scope.result!=='PASS') return {result:'BLOCKED',tooling_sha:readers.context.GITHUB_SHA ?? '',checks,missing_operations:['all dependent checks']};
    phase('ruleset_response',()=>describeRuleset(readers.api('repos/'+repository+'/rulesets/24808772')));
    phase('effective_rules_response',()=>{
        const effective=readers.api('repos/'+repository+'/rules/branches/main');
        const installed=readers.api('repos/'+repository+'/rulesets/24808772');
        verifyRulesetEffectivePolicy(installed,effective,repository,'main','Release qualification');
        return effective;
    });
    phase('token_permissions',()=>readers.api('repos/'+repository)?.permissions);
    phase('write_controls',()=>verifyWriteControls(repository,request,adapters));
    phase('original_qualification_and_provenance',()=>verifyRecoveryPublication(request,repository,record,adapters,false));
    let prepared;
    phase('prepared_assets',()=>{
        prepared=preparePublicationPayload(request,repository,record,adapters);
        return {sha256:prepared.frozenHashes,files:prepared.files,final_main:prepared.main.sha};
    });
    phase('release_inventory',()=>{
        if (!prepared) throw new Error('BLOCKED_ASSET_DEPENDENCY: prepared payload failed.');
        const releases=verifyPublicationInventory(repository,PUBLICATION_RECOVERY.tag,prepared,readers.command);
        return {complete_release_count:releases.length,matching_releases:releases.filter(item=>item.tag_name===PUBLICATION_RECOVERY.tag)};
    });
    return {result:Object.values(checks).every(check=>check.result==='PASS') ? 'PASS' : 'BLOCKED',
        tooling_sha:readers.context.GITHUB_SHA,checks,
        missing_operations:['fresh Environment approval and approval-to-write race','actual tag/draft/upload/publication enforcement',
            'production hosting and updater smoke','separately authorized develop reconciliation']};
}

/** Inspect the actual hosted job token and retain readiness without scheduling an Environment deployment.
 * @returns {void} Writes a safe local report/Actions summary and exits nonzero on any blocker.
 */
export function main() {
    const request=readRequest();
    const record=JSON.parse(readFileSync(resolve(process.env.RELEASE_RECORD ?? 'release-record/release-candidate.json'),'utf8'));
    const report=publicationReadiness(request,record,{api:readinessApi,command:readinessCommand,context:process.env});
    writeFileSync(resolve(process.env.RUNNER_TEMP,'publication-readiness.json'),JSON.stringify(report,null,2)+'\n');
    output('GITHUB_STEP_SUMMARY','Read-only publication readiness: '+report.result+'\nTooling SHA: '+report.tooling_sha+'\n\n'
        +Object.entries(report.checks).map(([name,check])=>'- '+name+': '+check.result+' '+check.code).join('\n'));
    process.stdout.write(JSON.stringify(report,null,2)+'\n');
    if (report.result!=='PASS') process.exitCode=1;
}

if (process.argv[1] && import.meta.url===pathToFileURL(resolve(process.argv[1])).href) main();
