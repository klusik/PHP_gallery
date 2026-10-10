/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-readiness.mjs
 * Module Type: Read-only Live Release Diagnostics
 * Purpose: Report whether the existing workflow token can prove active release controls.
 * Responsibilities:
 *   - Read actual effective and installed policies without changing GitHub configuration
 *   - Distinguish verified controls from unavailable bypass inventory or incompatible rules
 *   - Keep operational blockers visible without treating source CI as production acceptance
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {writeFileSync} from 'node:fs';
import {api,output} from './release-promotion.mjs';
import {requireOwnerRuleset} from './release-owner-authorization.mjs';

/** Inspect the live token's policy visibility and required branch controls without writes.
 * @param {string} repository Exact owner/repository.
 * @param {function(string,string=,(import('./release-promotion.mjs').JsonValue|null)=):import('./release-promotion.mjs').JsonValue} call Read-only API adapter whose response fields are independently checked by the policy owner.
 * @returns {{repository:string,main:{status:string,detail:string},develop:{status:string,detail:string},publication:string,operational_acceptance:string}} Independent verified or blocked controls and explicit unperformed live acceptance.
 */
export function inspectReadiness(repository,call=api) {
    const result={repository,main:{status:'BLOCKED',detail:''},develop:{status:'BLOCKED',detail:''},
        publication:'MANUAL_ONLY',operational_acceptance:'NOT_RUN'};
    for (const [branch,name,check] of [['main','Reviewed release promotion to main','Release qualification'],
        ['develop','Reviewed main-to-develop release reconciliation','Complete required CI matrix']]) {
        try {
            requireOwnerRuleset(call,repository,branch,name,check);
            result[branch]={status:'VERIFIED',detail:'Active compatible rules, no bypass actors and complete policy visibility.'};
        } catch (error) {result[branch]={status:'BLOCKED',detail:error.message};}
    }
    return result;
}

/** Retain actual workflow-token readback as a diagnostic artifact and summary.
 * @returns {void} Writes only runner evidence and never mutates GitHub state.
 */
export function main() {
    const result=inspectReadiness(process.env.GITHUB_REPOSITORY ?? '');
    writeFileSync(resolve(process.env.RUNNER_TEMP,'release-readiness.json'),JSON.stringify(result,null,2)+'\n');
    output('GITHUB_STEP_SUMMARY','Read-only release readiness (production acceptance remains NOT_RUN):\n'+JSON.stringify(result,null,2));
}
if (process.argv[1] && import.meta.url===pathToFileURL(resolve(process.argv[1])).href) main();
