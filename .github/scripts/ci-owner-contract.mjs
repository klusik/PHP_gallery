/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/ci-owner-contract.mjs
 * Module Type: Authenticated CI Owner Contract
 * Purpose: Keep current sharded PHP 8.1/8.5 owners strict while reading immutable historical qualification.
 * Responsibilities: Bind current and historical FULL owner mappings to exact reviewed workflow bytes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {createHash} from 'node:crypto';

/**
 * Return one complete native FULL owner contract without consulting candidate-authored metadata.
 * @param {1|2|3|4} [version=4] Historical contracts 1–3 retain mandatory macOS; contract 4 keeps the sharded matrix with mandatory Linux/Windows packaging and independent advisory macOS.
 * @returns {string[]} Exact required owner labels, including unchanged databases, runtimes and aggregate gate.
 */
export function fullMatrixOwners(version=4) {
    if (![1,2,3,4].includes(version)) throw new Error('BLOCKED unknown CI owner contract version.');
    const owners=['Read-only generated-state and source preflight',
        'Positive production package (ubuntu-24.04)','Positive production package (windows-2025)'];
    if (version<4) owners.push('Positive production package (macos-latest)');
    if (version>=3) {
        owners.push('Shared source contracts','Portable Node and WinApp');
        for (const database of ['mysql:8.4','mariadb:10.11','mariadb:11.4']) {
            for (let shard=1;shard<=4;shard++) {
                owners.push(`PHP 8.5 workflows (${database}, Unicode, shard ${shard}/4)`);
            }
        }
        for (let shard=1;shard<=4;shard++) owners.push(`PHP 8.1 source (unicode, shard ${shard}/4)`);
        for (let shard=1;shard<=4;shard++) owners.push(`PHP 8.5 source (ascii, shard ${shard}/4)`);
    } else {
        const databasePhp=version===1?'8.3':'8.5';
        owners.push(`PHP ${databasePhp} workflows (mysql:8.4, Unicode)`,
            `PHP ${databasePhp} workflows (mariadb:10.11, Unicode)`,
            'PHP 8.5 workflows (mariadb:11.4, Unicode)',
            'PHP 8.1 source (unicode)','PHP 8.5 source (ascii)');
    }
    owners.push('Required Chromium fixtures','Complete required CI matrix');
    return owners;
}

/**
 * Identify supplementary jobs whose outcomes never establish FULL success.
 * @returns {string[]} Exact advisory labels allowed alongside current required owners.
 */
export function advisoryMatrixOwners() {
    return ['Advisory macOS production package'];
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
 * Recognize only the reviewed historical or four-shard native database/runtime matrices.
 * @param {string} source Verified workflow source text.
 * @returns {1|2|3|4} Reviewed required-platform contract; unknown, partial or mixed matrices always throw.
 */
export function workflowOwnerVersion(source) {
    const block=name=>new RegExp('^  '+name+':\\n([\\s\\S]*?)(?=^  [a-z][a-z0-9-]*:|$(?![\\s\\S]))','m').exec(source)?.[1] || '';
    const databaseBlock=block('real-database-browser');
    const runtimeBlock=block('runtime-compatibility');
    const matrix=job=>/^      matrix:\n([\s\S]*?)(?=^    [a-z][a-z0-9-]*:|$(?![\s\S]))/m.exec(job)?.[1] || '';
    const databaseMatrix=matrix(databaseBlock);
    const runtimeMatrix=matrix(runtimeBlock);
    const databases=[...databaseMatrix.matchAll(/^          - php: ['"](8\.[0-9]+)['"]\n            database: ['"]([^'"]+)['"]\n            real_browser: (true|false)$/gm)]
        .map(match=>[match[1],match[2],match[3]]);
    const runtimes=[...runtimeMatrix.matchAll(/^          - php: ['"](8\.[0-9]+)['"]\n            normalization: (unicode|ascii)$/gm)]
        .map(match=>[match[1],match[2]]);
    const readAxis=(matrixSource,key)=>[...matrixSource.matchAll(new RegExp('^        '+key+': \\[([^\\]]+)\\]$','gm'))].map(match=>match[1]);
    const databaseShards=readAxis(databaseMatrix,'shard');
    const runtimeShards=readAxis(runtimeMatrix,'shard');
    const databaseAxis=readAxis(databaseMatrix,'database');
    const phpAxis=readAxis(runtimeMatrix,'php');
    const readAxisKeys=matrixSource=>[...matrixSource.matchAll(/^        ([a-z][a-z0-9_-]*):/gm)]
        .map(match=>match[1]).filter(key=>key!=='include');
    const databaseAxisKeys=readAxisKeys(databaseMatrix);
    const runtimeAxisKeys=readAxisKeys(runtimeMatrix);
    const expectedRuntimes=[['8.1','unicode'],['8.5','ascii']];
    const expectedDatabases=[['8.5','mysql:8.4','true'],['8.5','mariadb:10.11','false'],['8.5','mariadb:11.4','false']];
    if (databaseShards.length+runtimeShards.length+databaseAxis.length+phpAxis.length>0) {
        if (JSON.stringify(databaseShards)!==JSON.stringify(['1, 2, 3, 4'])
            || JSON.stringify(runtimeShards)!==JSON.stringify(['1, 2, 3, 4'])
            || JSON.stringify(databaseAxis)!==JSON.stringify(["'mysql:8.4', 'mariadb:10.11', 'mariadb:11.4'"])
            || JSON.stringify(phpAxis)!==JSON.stringify(["'8.1', '8.5'"])
            || JSON.stringify([...databaseAxisKeys].sort())!==JSON.stringify(['database','shard'])
            || JSON.stringify([...runtimeAxisKeys].sort())!==JSON.stringify(['php','shard'])
            || JSON.stringify(databases)!==JSON.stringify(expectedDatabases)
            || JSON.stringify(runtimes)!==JSON.stringify(expectedRuntimes)) {
            throw new Error('BLOCKED sharded workflow W owner matrix is unsupported or incomplete.');
        }
        const packageBlock=block('production-package');
        const packageRows=[...matrix(packageBlock).matchAll(/^          - os: ([a-z0-9.-]+)\n            php: ['"](8\.[0-9]+)['"]$/gm)]
            .map(match=>[match[1],match[2]]);
        if (readAxisKeys(matrix(packageBlock)).length!==0) {
            throw new Error('BLOCKED required package workflow contains an unsupported matrix axis.');
        }
        const requiredPackages=[['ubuntu-24.04','8.1'],['windows-2025','8.5']];
        if (JSON.stringify(packageRows)===JSON.stringify(requiredPackages)) {
            const advisory=block('macos-advisory');
            if (!advisory.includes("if: ${{ github.event_name == 'workflow_dispatch' && inputs.advisory_macos }}")
                || !advisory.includes('name: Advisory macOS production package')
                || !advisory.includes('runs-on: macos-latest')
                || !advisory.includes('php-version: \'8.5\'')
                || !advisory.includes('php scripts/audit.php --suite=production-package')
                || !block('ci-gate').includes("inputs.advisory_macos && 'Advisory macOS matrix gate (unused)' || 'Complete required CI matrix'")
                || !block('ci-gate').includes('always() && !inputs.advisory_macos')
                || /needs:[^\n]*macos-advisory/.test(source)) {
                throw new Error('BLOCKED advisory macOS is missing or coupled to required qualification.');
            }
            return 4;
        }
        if (JSON.stringify(packageRows)!==JSON.stringify([...requiredPackages,['macos-latest','8.5']])) {
            throw new Error('BLOCKED required production package matrix is unsupported or incomplete.');
        }
        return 3;
    }
    if (databaseAxisKeys.length!==0 || runtimeAxisKeys.length!==0) {
        throw new Error('BLOCKED historical workflow W contains an unsupported matrix axis.');
    }
    if (JSON.stringify(runtimes)!==JSON.stringify(expectedRuntimes)) {
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
 * @param {2|3|4} [expectedVersion=4] Exact current contract, or an explicitly authenticated retained historical matrix.
 * @returns {void} Returns only for the exact reviewed installed owner matrix; altered or historical definitions throw.
 */
export function requireCurrentWorkflowOwners(repository,workflowSha,call,expectedVersion=4) {
    const source=workflowSource(call,repository,workflowSha).text;
    const version=workflowOwnerVersion(source);
    if (![2,3,4].includes(expectedVersion) || version!==expectedVersion) {
        throw new Error('BLOCKED protected workflow does not define the exact requested owner matrix.');
    }
    const php='$'+'{{ matrix.php }}';
    const database='$'+'{{ matrix.database }}';
    const normalization='$'+'{{ matrix.normalization }}';
    const shard='$'+'{{ matrix.shard }}';
    const databaseLabel='name: PHP '+php+' workflows ('+database+', Unicode'+(version>=3?', shard '+shard+'/4':'')+')';
    const runtimeLabel='name: PHP '+php+' source ('+normalization+(version>=3?', shard '+shard+'/4':'')+')';
    if (!source.includes('php-version: '+php)
        || !source.includes(databaseLabel) || !source.includes(runtimeLabel)
        || version>=3 && (!source.includes('name: Shared source contracts') || !source.includes('name: Portable Node and WinApp'))
        || !source.includes('extensions: pdo_mysql, pdo_sqlite, curl, gd, zip, dom, mbstring, intl')) {
        throw new Error('BLOCKED protected-develop runtime provisioning differs from its declared native owner matrix.');
    }
}

/**
 * Authenticate current or historical owner requirements against immutable workflow proof.
 *
 * Fresh publishers use contract 4. Contract 3 retains its original required
 * macOS owner through verified W. Contracts 1 and 2 additionally require W to
 * match authenticated common-main history. Existing records remain intact.
 *
 * @param {Array<{name:string}>} jobs Actual retained native job/evidence rows.
 * @param {string} repository Exact owner/repository.
 * @param {string|undefined} workflowSha Server-recorded immutable source W; unavailable identity always blocks.
 * @param {function(string):unknown} call Independent read-only GitHub API reader.
 * @param {boolean} [complete=true] Require every runtime/database owner; false allows only correctly named optional skipped-path metadata.
 * @returns {string[]} Exactly one reviewed owner set; unavailable W, unsupported matrices or mixed/missing native rows throw.
 */
export function evidenceMatrixOwners(jobs,repository,workflowSha,call,complete=true) {
    const observed=jobs.map(job=>job.name.split(' / ').at(-1));
    const rows=observed.filter(name=>/^PHP \d+\.\d+ (workflows|source) \(/.test(name));
    const hasShardedRows=rows.some(name=>/shard \d+\/4\)$/.test(name));
    const hasLegacyPhp83=rows.some(name=>name.startsWith('PHP 8.3 '));
    if (hasShardedRows && hasLegacyPhp83) throw new Error('BLOCKED mixed historical and sharded native owner rows.');
    let version=hasShardedRows ? 3 : (hasLegacyPhp83 ? 1 : 2);
    if (rows.length===0 && !complete) {
        if (typeof call!=='function' || typeof workflowSha!=='string') {
            throw new Error('BLOCKED partial native owner evidence has no immutable workflow source.');
        }
        version=workflowOwnerVersion(workflowSource(call,repository,workflowSha).text);
    }
    if (version===1 || version===2) {
        if (typeof call!=='function') throw new Error('BLOCKED historical workflow W reader is unavailable.');
        const workflow=workflowSource(call,repository,workflowSha);
        const main=call(`repos/${repository}/git/ref/heads/main`);
        if (main?.ref!=='refs/heads/main' || main.object?.type!=='commit' || !/^[a-f0-9]{40}$/.test(main.object?.sha ?? '')) {
            throw new Error('BLOCKED historical workflow W main-history authority is unavailable.');
        }
        const ancestor=workflowSha===main.object.sha ? workflowSha
            : call(`repos/${repository}/compare/${workflowSha}...${main.object.sha}`)?.merge_base_commit?.sha;
        const inherited=ancestor===workflowSha ? workflow : workflowSource(call,repository,ancestor);
        const authenticatedVersion=workflowOwnerVersion(workflow.text);
        if (workflow.sha!==inherited.sha || workflowOwnerVersion(inherited.text)!==authenticatedVersion
            || authenticatedVersion!==version || authenticatedVersion===3) {
            throw new Error('BLOCKED legacy PHP owner names do not match workflow W inherited from main history.');
        }
    } else {
        if (typeof workflowSha!=='string' || typeof call!=='function') {
            throw new Error('BLOCKED sharded native owner rows lack their immutable workflow W reader.');
        }
        version=workflowOwnerVersion(workflowSource(call,repository,workflowSha).text);
        if (![3,4].includes(version)) throw new Error('BLOCKED sharded workflow W has a historical unsharded matrix.');
        requireCurrentWorkflowOwners(repository,workflowSha,call,version);
    }
    const owners=fullMatrixOwners(version);
    const matrixOwners=owners.filter(name=>name.startsWith('PHP '));
    if (rows.some(name=>!matrixOwners.includes(name)) || new Set(rows).size!==rows.length
        || complete && (matrixOwners.some(name=>!rows.includes(name))
            || owners.some(name=>!observed.includes(name))
            || owners.some(name=>observed.filter(value=>value===name).length!==1))) {
        throw new Error('BLOCKED native FULL owners are mixed, missing or duplicated.');
    }
    return owners;
}
