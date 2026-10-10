/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/release-assets.mjs
 * Module Type: Release Asset Preparation
 * Purpose: Build public assets and bind their complete bytes to the qualified candidate.
 * Responsibilities:
 *   - Use canonical release checks and positive production membership
 *   - Preserve complete hosted evidence in a durable archive
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {mkdirSync,copyFileSync,readFileSync,writeFileSync,readdirSync,utimesSync,lstatSync} from 'node:fs';
import {resolve} from 'node:path';
import {createHash} from 'node:crypto';
import {command} from './release-promotion.mjs';
import {verifyPreparedAssets} from './release-asset-integrity.mjs';

/** Normalize archive metadata in an owned staging tree without following links.
 * @param {string} directory Absolute owned staging/evidence directory.
 * @param {number} epoch Immutable candidate timestamp in Unix seconds.
 * @returns {void} Sets file/directory times or refuses symbolic links.
 */
function normalizeArchiveTimes(directory, epoch) {
    for (const name of readdirSync(directory).sort()) {
        const path = resolve(directory,name);
        const stat = lstatSync(path);
        if (stat.isSymbolicLink()) throw new Error('BLOCKED release archive contains a symbolic link.');
        if (stat.isDirectory()) normalizeArchiveTimes(path,epoch);
        utimesSync(path,epoch,epoch);
    }
    utimesSync(directory,epoch,epoch);
}

const workspace = resolve(process.env.CANDIDATE_WORKSPACE);
const assets = resolve(process.env.RELEASE_ASSETS);
const evidence = resolve(process.env.QUALIFICATION_EVIDENCE);
process.chdir(workspace);
if (command('git',['rev-parse','HEAD']) !== process.env.CANDIDATE_SHA) throw new Error('BLOCKED package checkout differs.');
command('php',['scripts/prepare_candidate.php','--check']);
command('php',['scripts/check_release.php',process.env.RELEASE_VERSION]);
mkdirSync(assets,{recursive:true});
const packageDirectory = resolve(assets,'../production-stage');
command('bash',['scripts/deploy.sh','--mode','local','--deploy-folder',packageDirectory,
    '--upload-media','false','--include-tests','false','--make-zip-deploy','false']);
const epoch = Number(command('git',['show','-s','--format=%ct',process.env.CANDIDATE_SHA]));
normalizeArchiveTimes(packageDirectory,epoch);
process.env.TZ = 'UTC';
process.chdir(packageDirectory);
command('zip',['-Xqr',resolve(assets,'production.zip'),'.']);
command('unzip',['-tq',resolve(assets,'production.zip')]);
process.chdir(workspace);
for (const name of ['core-manifest.json','production-files.json']) copyFileSync(resolve('app',name),resolve(assets,name));
copyFileSync('release-metadata.json',resolve(assets,'release-metadata.json'));
for (const edition of ['', '_CZ', '_DE', '_SV']) {
    const name='PHP_Gallery_Manual'+edition+'.pdf';
    copyFileSync(resolve('docs',name),resolve(assets,name));
}
const notes = readFileSync('PATCH_NOTES.md','utf8');
const start = notes.indexOf(`## Version ${process.env.RELEASE_VERSION}`);
if (start < 0) throw new Error('BLOCKED release note section unavailable.');
const end = notes.indexOf('\n## Version ',start + 1);
writeFileSync(resolve(assets,'release-notes.md'),notes.slice(start,end < 0 ? notes.length : end));
// Archive only the owned downloaded reports, never application or runner state.
normalizeArchiveTimes(evidence,epoch);
process.chdir(evidence);
command('zip',['-Xqr',resolve(assets,'qualification-evidence.zip'),'.']);
const hashes = {};
for (const name of readdirSync(assets).sort()) hashes[name] = createHash('sha256').update(readFileSync(resolve(assets,name))).digest('hex');
writeFileSync(resolve(assets,'SHA256SUMS'),Object.entries(hashes).map(([name,digest]) => `${digest}  ${name}`).join('\n') + '\n');
hashes.SHA256SUMS = createHash('sha256').update(readFileSync(resolve(assets,'SHA256SUMS'))).digest('hex');
writeFileSync(resolve(assets,'final-integrity.json'),JSON.stringify({candidate_sha:process.env.CANDIDATE_SHA,
    source_base:process.env.SOURCE_BASE,result:'PASS',hashes},null,2) + '\n');
verifyPreparedAssets(assets,process.env.CANDIDATE_SHA,process.env.SOURCE_BASE);
