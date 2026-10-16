/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/trusted-basic.mjs
 * Module Type: Inherited BASIC Authority Adapter
 * Purpose: Run protected evaluator code against a separate clean candidate checkout.
 * Responsibilities:
 *   - Bind only the inherited evaluator's repository-root function to the selected candidate
 *   - Preserve candidate bytes and verify the original central BASIC report and exact Git identities
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {createHash} from 'node:crypto';
import {readFileSync,writeFileSync,realpathSync} from 'node:fs';
import {resolve,relative,isAbsolute,sep} from 'node:path';
import {pathToFileURL} from 'node:url';
import {spawnSync} from 'node:child_process';

/** Independent central audit identity retained in the protected integration record.
 * @typedef {{schema_version:1,authority_sha:string,source_sha:string,source_tree:string,comparison_base:string,profile:'basic',suites:string[],successful:true,report_sha256:string,root_binding:'trusted-evaluator-candidate-root-v1'}} TrustedBasicEvidence
 */
/** Original inherited BASIC result containing the exact inspected checkout and all central task outcomes.
 * @typedef {{profile:string,status:string,source:{head_sha:string,comparison_base:string,worktree_dirty:boolean},tasks:Array<{id:string,status:string}>}} InheritedBasicReport
 */

/** Exact central suites required from the inherited evaluator, without candidate-authored selection.
 * Type: string[]. Units: central suite identifiers. Scope: one CI authority transition.
 * Consumers: trusted report verification and integration evidence.
 * Rationale: whole-tree syntax plus changed declaration documentation preserves the installed BASIC gate.
 */
export const INHERITED_BASIC_SUITES=['php-lint','js-lint','source-documentation-changed'];

/** Bound each inherited Git/PHP child while retaining the installed BASIC audit's own suite deadlines.
 * Type: number. Units: milliseconds. Scope: inherited evaluator adapter process.
 * Consumers: runInheritedCommand.
 * Rationale: ten minutes matches the workflow's bounded BASIC execution and prevents abandoned children.
 */
const INHERITED_BASIC_TIMEOUT_MS=600000;
/** Retain complete bounded source/report output without silently accepting truncated evidence.
 * Type: number. Units: bytes. Scope: one inherited tooling child output capture.
 * Consumers: runInheritedCommand.
 * Rationale: sixteen MiB covers normal central reports while overflow remains an explicit failure.
 */
const INHERITED_BASIC_OUTPUT_BYTES=16*1024*1024;

/** Bind the single reviewed root accessor without changing any audit selection or enforcement.
 * @param {string} source Complete inherited audit_lib.php source bytes decoded as UTF-8.
 * @param {string} candidate Absolute candidate root selected by the protected workflow.
 * @returns {string} Evaluator source with only the root accessor replaced; unknown implementations throw.
 */
export function bindTrustedBasicRoot(source,candidate) {
    if (!isAbsolute(candidate) || /[\r\n\0]/.test(candidate)) throw new Error('BLOCKED invalid trusted BASIC candidate root.');
    const pattern=/function project_root\(\): string\r?\n\{\r?\n    return dirname\(__DIR__\);\r?\n\}/g;
    const matches=[...source.matchAll(pattern)];
    if (matches.length!==1) throw new Error('BLOCKED inherited BASIC root accessor is unknown or ambiguous.');
    const literal="'"+candidate.replace(/\\/g,'\\\\').replace(/'/g,"\\'")+"'";
    return source.replace(pattern,'function project_root(): string\n{\n    return '+literal+';\n}');
}

/** Require clean exact candidate and complete original central report before retaining independent evidence.
 * @param {InheritedBasicReport} report Parsed original central BASIC report.
 * @param {{authority:string,source:string,tree:string,base:string}} identity Verified workflow and clean candidate Git identities.
 * @param {Buffer} bytes Original central report bytes, retained by their SHA-256.
 * @returns {TrustedBasicEvidence} Exact independent BASIC evidence; incomplete or dirty reports throw.
 */
export function bindTrustedBasicReport(report,identity,bytes) {
    if (Object.values(identity).some(value=>!/^[a-f0-9]{40}$/.test(value))
        || report?.profile!=='basic' || report.status!=='PASS'
        || report.source?.head_sha!==identity.source || report.source?.comparison_base!==identity.base
        || report.source?.worktree_dirty!==false || !Array.isArray(report.tasks)
        || report.tasks.length!==INHERITED_BASIC_SUITES.length
        || INHERITED_BASIC_SUITES.some(id=>report.tasks.filter(task=>task.id===id && task.status==='PASS').length!==1)) {
        throw new Error('BLOCKED inherited BASIC report does not qualify the clean selected candidate.');
    }
    return {schema_version:1,authority_sha:identity.authority,source_sha:identity.source,source_tree:identity.tree,
        comparison_base:identity.base,profile:'basic',suites:[...INHERITED_BASIC_SUITES],successful:true,
        report_sha256:'sha256:'+createHash('sha256').update(bytes).digest('hex'),root_binding:'trusted-evaluator-candidate-root-v1'};
}

/** Execute literal bounded tooling commands with diagnostics separate from returned data.
 * @param {string} executable Required Git or PHP executable name.
 * @param {string[]} args Literal command arguments, never shell fragments.
 * @param {string} cwd Candidate checkout used for source identity and scanning.
 * @param {NodeJS.ProcessEnv} env Explicit inherited environment and frozen comparison base.
 * @returns {string} Complete UTF-8 stdout; failure, timeout or overflow throws.
 */
function runInheritedCommand(executable,args,cwd,env) {
    const child=spawnSync(executable,args,{cwd,env,encoding:'utf8',shell:false,timeout:INHERITED_BASIC_TIMEOUT_MS,maxBuffer:INHERITED_BASIC_OUTPUT_BYTES});
    if (child.error || child.status!==0) {
        process.stderr.write(String(child.stdout ?? '')+String(child.stderr ?? ''));
        throw new Error('BLOCKED inherited BASIC command failed: '+executable+' ('+String(child.status)+').');
    }
    return child.stdout;
}

/** Run the inherited central evaluator with its sole root binding in a separate authority directory.
 * @param {{authority:string,candidate:string,output:string}} options Absolute authority archive, candidate checkout and evidence output.
 * @param {NodeJS.ProcessEnv} env Protected workflow values AUTHORITY_SHA, SOURCE_SHA and DEVELOP_SHA.
 * @returns {void} Writes independent evidence and preserves the complete original audit report beside it.
 */
export function runTrustedBasic(options,env=process.env) {
    const authority=realpathSync(options.authority),candidate=realpathSync(options.candidate);
    const separation=relative(candidate,authority);
    if (!separation || (separation!=='..' && !separation.startsWith('..'+sep) && !isAbsolute(separation))) {
        throw new Error('BLOCKED authority evaluator must be outside the candidate checkout.');
    }
    const git=args=>runInheritedCommand('git',args,candidate,env).trim();
    const source=git(['rev-parse','HEAD']),tree=git(['rev-parse','HEAD^{tree}']);
    if ([env.SOURCE_SHA,env.AUTHORITY_SHA,env.DEVELOP_SHA].some(value=>!/^[a-f0-9]{40}$/.test(value ?? ''))
        || source!==env.SOURCE_SHA || git(['status','--porcelain'])!=='') {
        throw new Error('BLOCKED inherited BASIC source or authority lease differs.');
    }
    const library=resolve(authority,'scripts/audit_lib.php');
    const original=readFileSync(library,'utf8');
    const inherited=runInheritedCommand('git',['show',env.AUTHORITY_SHA+':scripts/audit_lib.php'],candidate,env);
    if (original!==inherited) throw new Error('BLOCKED authority archive differs from frozen evaluator bytes.');
    const auditEnv={...env,PHP_GALLERY_SOURCE_BASE:env.DEVELOP_SHA,PHP_GALLERY_BROWSER:'disabled',GALLERY_WORKFLOW_BROWSER:'disabled'};
    writeFileSync(library,bindTrustedBasicRoot(original,candidate));
    try {
        const output=runInheritedCommand('php',[resolve(authority,'scripts/audit.php'),'--profile=basic'],candidate,auditEnv);
        process.stdout.write(output);
        if (git(['rev-parse','HEAD'])!==source || git(['status','--porcelain'])!=='') {
            throw new Error('BLOCKED candidate changed during inherited BASIC inspection.');
        }
        const bytes=readFileSync(resolve(candidate,'cache/test-audit/latest.json'));
        const bound=bindTrustedBasicReport(JSON.parse(bytes.toString('utf8')),
            {authority:env.AUTHORITY_SHA,source,tree,base:env.DEVELOP_SHA},bytes);
        writeFileSync(options.output,JSON.stringify(bound,null,2)+'\n');
        writeFileSync(options.output+'.report.json',bytes);
    } finally {
        writeFileSync(library,original);
    }
}

if (process.argv[1] && import.meta.url===pathToFileURL(resolve(process.argv[1])).href) {
    try {
        const options={};
        for (const argument of process.argv.slice(2)) {
            const match=/^--(authority|candidate|output)=(.+)$/.exec(argument);
            if (!match || options[match[1]]) throw new Error('BLOCKED invalid inherited BASIC option.');
            options[match[1]]=match[2];
        }
        if (Object.keys(options).length!==3) throw new Error('BLOCKED incomplete inherited BASIC paths.');
        runTrustedBasic(options);
    } catch (error) {process.stderr.write(error.message+'\n');process.exitCode=1;}
}
