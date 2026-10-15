/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/ci-owner-contract.mjs
 * Module Type: Authenticated CI Owner Contract
 * Purpose: Keep current PHP 8.1/8.5 owners strict while reading immutable historical qualification.
 * Responsibilities: Bind the legacy database owner mapping to verified workflow bytes inherited from main history.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {createHash} from 'node:crypto';

/**
 * Return one complete native FULL owner contract without consulting candidate-authored metadata.
 * @param {1|2} [version=2] Contract 1 is historical PHP 8.3 database coverage; contract 2 is current PHP 8.5 database coverage.
 * @returns {string[]} Exact required owner labels, including unchanged databases, runtimes and aggregate gate.
 */
export function fullMatrixOwners(version=2) {
    if (![1,2].includes(version)) throw new Error('BLOCKED unknown CI owner contract version.');
    const databasePhp=version===1?'8.3':'8.5';
    return ['Read-only generated-state and source preflight',
        'Positive production package (ubuntu-24.04)','Positive production package (windows-2025)',
        'Positive production package (macos-latest)',
        `PHP ${databasePhp} workflows (mysql:8.4, Unicode)`,
        `PHP ${databasePhp} workflows (mariadb:10.11, Unicode)`,
        'PHP 8.5 workflows (mariadb:11.4, Unicode)',
        'PHP 8.1 source (unicode)','PHP 8.5 source (ascii)',
        'Required Chromium fixtures','Complete required CI matrix'];
}

/**
 * Read an exact complete workflow source blob and verify the provider's Git object identity.
 * @param {function(string):unknown} call Independent read-only GitHub API reader.
 * @param {string} repository Exact owner/repository.
 * @param {string} commit Immutable workflow source commit.
 * @param {string} [path='.github/workflows/gallery-workflows.yml'] Reviewed matrix or preparation workflow path.
 * @returns {{sha:string,text:string}} Verified UTF-8 workflow source and Git blob identity.
 */
function workflowSource(call,repository,commit,path='.github/workflows/gallery-workflows.yml') {
    if (!/^[a-f0-9]{40}$/.test(commit ?? '')) throw new Error('BLOCKED historical workflow W is unavailable.');
    if (!['.github/workflows/gallery-workflows.yml','.github/workflows/candidate-preparation.yml'].includes(path)) {
        throw new Error('BLOCKED unknown workflow owner source path.');
    }
    const record=call(`repos/${repository}/contents/${path}?ref=${commit}`);
    if (record?.type!=='file' || record.path!==path || record.encoding!=='base64'
        || !/^[a-f0-9]{40}$/.test(record.sha ?? '') || !Number.isSafeInteger(record.size)
        || record.size<1 || record.size>1048576 || typeof record.content!=='string') {
        throw new Error('BLOCKED historical workflow W source is missing or incomplete.');
    }
    const encoded=record.content.replace(/\s/g,'');
    if (!/^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(encoded)) {
        throw new Error('BLOCKED historical workflow W content encoding is invalid.');
    }
    const bytes=Buffer.from(encoded,'base64');
    const actual=createHash('sha1').update(Buffer.concat([Buffer.from(`blob ${bytes.length}\0`),bytes])).digest('hex');
    const text=bytes.toString('utf8');
    if (bytes.length!==record.size || actual!==record.sha || !Buffer.from(text,'utf8').equals(bytes)) {
        throw new Error('BLOCKED historical workflow W bytes differ from their Git object identity.');
    }
    return {sha:record.sha,text:text.replace(/\r\n/g,'\n')};
}

/**
 * Bind retained BASIC suite identifiers to the actual immutable main-owned preparation workflow at W.
 * @param {string[]} profile Recorded central BASIC task identifiers.
 * @param {string} repository Exact owner/repository.
 * @param {string} workflowSha Already authenticated main-owned workflow source W.
 * @param {function(string):unknown} call Independent read-only GitHub API reader.
 * @returns {void} Returns only for the complete profile actually invoked by W; old and current records remain distinct.
 */
export function requireIntegrationBasicProfile(profile,repository,workflowSha,call) {
    const source=workflowSource(call,repository,workflowSha,'.github/workflows/candidate-preparation.yml').text;
    const invocations=[...source.matchAll(/^\s+(?:run:\s*)?php scripts\/audit\.php --profile=basic( --changed)?\s*$/gm)];
    if (invocations.length!==1) throw new Error('BLOCKED immutable BASIC workflow invocation is missing or ambiguous.');
    const expected=invocations[0][1]
        ? ['php-lint-changed','js-lint-changed','source-documentation-changed']
        : ['php-lint','js-lint','source-documentation-changed'];
    if (JSON.stringify(profile)!==JSON.stringify(expected)) {
        throw new Error('BLOCKED integration BASIC profile differs from the authenticated workflow W.');
    }
}

/**
 * Recognize only the two reviewed native database/runtime matrix contracts in complete workflow source.
 * @param {string} source Verified workflow source text.
 * @returns {1|2} Reviewed contract version; unknown, partial or mixed matrices always throw.
 */
export function workflowOwnerVersion(source) {
    const block=name=>new RegExp('^  '+name+':\\n([\\s\\S]*?)(?=^  [a-z][a-z0-9-]*:|$(?![\\s\\S]))','m').exec(source)?.[1] || '';
    const databases=[...block('real-database-browser').matchAll(/^          - php: ['"](8\.[0-9]+)['"]\n            database: ['"]([^'"]+)['"]\n            real_browser: (true|false)$/gm)]
        .map(match=>[match[1],match[2],match[3]]);
    const runtimes=[...block('runtime-compatibility').matchAll(/^          - php: ['"](8\.[0-9]+)['"]\n            normalization: (unicode|ascii)$/gm)]
        .map(match=>[match[1],match[2]]);
    if (JSON.stringify(runtimes)!==JSON.stringify([['8.1','unicode'],['8.5','ascii']])) {
        throw new Error('BLOCKED historical workflow W runtime matrix is unsupported or incomplete.');
    }
    for (const version of [1,2]) {
        const php=version===1?'8.3':'8.5';
        if (JSON.stringify(databases)===JSON.stringify([[php,'mysql:8.4','true'],[php,'mariadb:10.11','false'],['8.5','mariadb:11.4','false']])) {
            return version;
        }
    }
    throw new Error('BLOCKED historical workflow W database matrix is unsupported or incomplete.');
}

/** Authenticate current workflow bytes before trusting a protected-develop installation's native labels.
 * @param {string} repository Exact owner/repository identity.
 * @param {string} workflowSha Previously authenticated protected workflow commit W.
 * @param {function(string):unknown} call Independent immutable GitHub source reader.
 * @returns {void} Returns only for the complete current PHP 8.1/8.5 matrix; altered or historical definitions throw.
 */
export function requireCurrentWorkflowOwners(repository,workflowSha,call) {
    const source=workflowSource(call,repository,workflowSha).text;
    if (workflowOwnerVersion(source)!==2) {
        throw new Error('BLOCKED protected-develop workflow does not define the current PHP 8.1/8.5 matrix.');
    }
    if (!/php-version: \$\{\{ matrix\.php \}\}/.test(source)
        || !/name: PHP \$\{\{ matrix\.php \}\} workflows \(\$\{\{ matrix\.database \}\}, Unicode\)/.test(source)
        || !source.includes('extensions: pdo_mysql, pdo_sqlite, curl, gd, zip, dom, mbstring, intl')) {
        throw new Error('BLOCKED protected-develop runtime provisioning differs from its declared native owner matrix.');
    }
}

/**
 * Select historical owners only with immutable workflow and inherited main-history proof.
 *
 * Fresh publishers use fullMatrixOwners() directly and can emit only contract 2.
 * Readers may accept contract 1 when W's verified workflow is identical to its
 * authenticated common-main ancestor. Existing records and their schema remain intact.
 *
 * @param {Array<{name:string}>} jobs Actual retained native job/evidence rows.
 * @param {string} repository Exact owner/repository.
 * @param {string|undefined} workflowSha Server-recorded immutable source W; required only for historical owner names.
 * @param {function(string):unknown} call Independent read-only GitHub API reader.
 * @param {boolean} [complete=true] Require every runtime/database owner; false allows only correctly named optional skipped-path metadata.
 * @returns {string[]} Exactly one reviewed owner set; unavailable W, unsupported matrices or mixed/missing native rows throw.
 */
export function evidenceMatrixOwners(jobs,repository,workflowSha,call,complete=true) {
    const rows=jobs.map(job=>job.name.split(' / ').at(-1)).filter(name=>/^PHP \d+\.\d+ (workflows|source) \(/.test(name));
    let version=2;
    if (rows.some(name=>name.startsWith('PHP 8.3 '))) {
        if (typeof call!=='function') throw new Error('BLOCKED historical workflow W reader is unavailable.');
        const workflow=workflowSource(call,repository,workflowSha);
        const main=call(`repos/${repository}/git/ref/heads/main`);
        if (main?.ref!=='refs/heads/main' || main.object?.type!=='commit' || !/^[a-f0-9]{40}$/.test(main.object?.sha ?? '')) {
            throw new Error('BLOCKED historical workflow W main-history authority is unavailable.');
        }
        const ancestor=workflowSha===main.object.sha ? workflowSha
            : call(`repos/${repository}/compare/${workflowSha}...${main.object.sha}`)?.merge_base_commit?.sha;
        const inherited=ancestor===workflowSha ? workflow : workflowSource(call,repository,ancestor);
        if (workflow.sha!==inherited.sha || workflowOwnerVersion(workflow.text)!==1) {
            throw new Error('BLOCKED legacy PHP owner names do not match workflow W inherited from main history.');
        }
        version=1;
    }
    const owners=fullMatrixOwners(version);
    const matrixOwners=owners.filter(name=>name.startsWith('PHP '));
    if (rows.some(name=>!matrixOwners.includes(name)) || new Set(rows).size!==rows.length
        || complete && matrixOwners.some(name=>!rows.includes(name))) {
        throw new Error('BLOCKED runtime/database native owners are mixed, missing or duplicated.');
    }
    return owners;
}
