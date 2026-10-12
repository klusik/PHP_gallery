/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/project-website-dispatch.mjs
 * Module Type: Reviewed Website Publication Handoff
 * Purpose: Refresh the reviewed main website after a stable release without deploying a tag ref.
 * Responsibilities: Validate publication and Pages configuration, then dispatch only the main-owned website workflow.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {api,output} from './release-promotion.mjs';

/** Literal GitHub API transport; the only permitted write below dispatches the website workflow on main.
 * @typedef {function(string,string=,(import('./release-promotion.mjs').JsonValue|null)=):import('./release-promotion.mjs').JsonValue} WebsiteApi
 */

/** Dispatch a fresh main build only for the latest stable publication and configured Actions-based Pages.
 * @param {{repository:string,tag:string}} request Same-repository stable release tag that caused the event.
 * @param {{repository:string,eventName:string,ref:string}} context Actual GitHub event/ref identity; a checkout cannot change this identity.
 * @param {WebsiteApi} call Optional isolated API adapter, defaulting to the authenticated provider.
 * @returns {{state:'DISPATCHED'|'SUPERSEDED',ref:'main',release_tag:string}} Main-only handoff or a superseded publication with no write; invalid/configuration inputs throw.
 */
export function dispatchPublishedWebsite(request,context,call=api) {
    if (!/^[\w.-]+\/[\w.-]+$/.test(request.repository)
        || !/^v_(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?$/.test(request.tag)
        || context.repository!==request.repository || context.eventName!=='release'
        || context.ref!=='refs/tags/'+request.tag) throw new Error('BLOCKED exact stable release publication context required.');
    const prefix='repos/'+request.repository;
    const latest=call(prefix+'/releases/latest');
    if (latest?.draft!==false || latest.prerelease!==false || !Number.isSafeInteger(latest.id) || latest.id<1) {
        throw new Error('BLOCKED latest stable publication is unavailable.');
    }
    if (latest.tag_name!==request.tag) return {state:'SUPERSEDED',ref:'main',release_tag:request.tag};
    const pages=call(prefix+'/pages');
    if (pages?.build_type!=='workflow') {
        throw new Error('BLOCKED set Settings > Pages > Build and deployment > Source to GitHub Actions, then run Project website from main.');
    }
    const main=call(prefix+'/git/ref/heads/main');
    if (main?.ref!=='refs/heads/main' || main.object?.type!=='commit' || !/^[a-f0-9]{40}$/.test(main.object.sha)) {
        throw new Error('BLOCKED reviewed main website identity is unavailable.');
    }
    const workflow=call(prefix+'/contents/.github/workflows/project-website.yml?ref='+main.object.sha);
    if (workflow?.type!=='file' || workflow.path!=='.github/workflows/project-website.yml'
        || !/^[a-f0-9]{40}$/.test(workflow.sha)) throw new Error('BLOCKED website workflow is not installed on main.');
    call(prefix+'/actions/workflows/project-website.yml/dispatches','POST',{ref:'main'});
    return {state:'DISPATCHED',ref:'main',release_tag:request.tag};
}

/** Route the trusted release event into the read-validated main website dispatcher.
 * @returns {void} Writes only a main workflow dispatch and its summary; errors stop without Pages or ref writes.
 */
function main() {
    const event=JSON.parse(readFileSync(process.env.GITHUB_EVENT_PATH,'utf8'));
    const result=dispatchPublishedWebsite({repository:process.env.GITHUB_REPOSITORY ?? '',tag:event.release?.tag_name ?? ''},
        {repository:process.env.GITHUB_REPOSITORY ?? '',eventName:process.env.GITHUB_EVENT_NAME ?? '',ref:process.env.GITHUB_REF ?? ''});
    output('GITHUB_STEP_SUMMARY','Published website handoff: '+result.state+'; ref '+result.ref+'; release '+result.release_tag+'.');
}
if (process.argv[1] && import.meta.url===pathToFileURL(resolve(process.argv[1])).href) {
    try {main();} catch (error) {process.stderr.write(error.message+'\n');process.exitCode=1;}
}
