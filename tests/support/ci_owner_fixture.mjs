/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/ci_owner_fixture.mjs
 * Module Type: Inert Workflow Identity Fixture
 * Purpose: Supply complete hashed current and historical workflow sources to the real owner validator.
 * Responsibilities: Preserve reviewed immutable matrix versions and exercise legacy evidence without external writes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {readFileSync} from 'node:fs';
import {createHash} from 'node:crypto';

/**
 * Build one complete synthetic workflow matrix for an immutable owner-contract fixture.
 * @param {1|2|3|4} version Historical matrices 1–3 or current sharded coverage with advisory macOS.
 * @returns {string} Workflow source whose matrix and labels identify exactly the requested reviewed contract.
 */
function ownerWorkflowSource(version) {
    const databasePhp=version===1?'8.3':'8.5';
    const databaseEntries=[
        ['mysql:8.4',databasePhp,'true'],
        ['mariadb:10.11',databasePhp,'false'],
        ['mariadb:11.4','8.5','false']];
    const databaseRows=databaseEntries.map(([database,php,realBrowser])=>
        "          - php: '"+php+"'\n            database: '"+database+"'\n            real_browser: "+realBrowser).join('\n');
    const runtimeRows=[
        "          - php: '8.1'\n            normalization: unicode",
        "          - php: '8.5'\n            normalization: ascii"].join('\n');
    const databaseMatrix=version>=3
        ? "        database: ['mysql:8.4', 'mariadb:10.11', 'mariadb:11.4']\n        shard: [1, 2, 3, 4]\n        include:\n"+databaseRows
        : "        include:\n"+databaseRows;
    const runtimeMatrix=version>=3
        ? "        php: ['8.1', '8.5']\n        shard: [1, 2, 3, 4]\n        include:\n"+runtimeRows
        : "        include:\n"+runtimeRows;
    const matrixPhp='$'+'{{ matrix.php }}';
    const matrixDatabase='$'+'{{ matrix.database }}';
    const matrixNormalization='$'+'{{ matrix.normalization }}';
    const matrixShard='$'+'{{ matrix.shard }}';
    const databaseName=version>=3
        ? 'PHP '+matrixPhp+' workflows ('+matrixDatabase+', Unicode, shard '+matrixShard+'/4)'
        : 'PHP '+matrixPhp+' workflows ('+matrixDatabase+', Unicode)';
    const runtimeName=version>=3
        ? 'PHP '+matrixPhp+' source ('+matrixNormalization+', shard '+matrixShard+'/4)'
        : 'PHP '+matrixPhp+' source ('+matrixNormalization+')';
    const extraJobs=version>=3
        ? ['  shared-source-contracts:','    name: Shared source contracts',
            '  portable-node-winapp:','    name: Portable Node and WinApp']
        : [];
    const packageJobs=[
        '  production-package:','    strategy:','      matrix:','        include:',
        "          - os: ubuntu-24.04\n            php: '8.1'",
        "          - os: windows-2025\n            php: '8.5'",
        ...(version<4 ? ["          - os: macos-latest\n            php: '8.5'"] : []),
    ];
    if (version===4) packageJobs.push(
        '  macos-advisory:',
        "    if: ${{ github.event_name == 'workflow_dispatch' && inputs.advisory_macos }}",
        '    name: Advisory macOS production package',
        '    runs-on: macos-latest',
        "    php-version: '8.5'",
        '    run: php scripts/audit.php --suite=production-package',
        '  ci-gate:',
        "    name: ${{ inputs.advisory_macos && 'Advisory macOS matrix gate (unused)' || 'Complete required CI matrix' }}",
        '    if: ${{ always() && !inputs.advisory_macos }}',
    );
    return [
        'name: Synthetic owner contract','jobs:',
        '  real-database-browser:','    name: '+databaseName,'    strategy:',
        '      matrix:',databaseMatrix,
        '    steps:','      - uses: shivammathur/setup-php@v2','        with:',
        '          php-version: '+matrixPhp,
        '          extensions: pdo_mysql, pdo_sqlite, curl, gd, zip, dom, mbstring, intl',
        '  runtime-compatibility:','    name: '+runtimeName,'    strategy:',
        '      matrix:',runtimeMatrix,
        '    steps:','      - uses: shivammathur/setup-php@v2','        with:',
        '          php-version: '+matrixPhp,...extraJobs,...packageJobs].join('\n')+'\n';
}

/**
 * Produce a complete Contents response for one reviewed immutable workflow fixture request.
 * @param {string} request Literal API request path.
 * @param {1|2|3|4} [version=4] Historical or current owner/BASIC invocation contract.
 * @returns {{type:string,path:string,sha:string,encoding:string,size:number,content:string}|null} Complete Git-hashed source response, or null for an unrelated endpoint.
 */
export function ciOwnerWorkflowResponse(request,version=4) {
    const match=/\/contents\/(\.github\/workflows\/(?:gallery-workflows|candidate-preparation)\.yml)\?ref=[a-f0-9]{40}$/.exec(request);
    if (!match) return null;
    const path=match[1];
    let source=path.endsWith('/gallery-workflows.yml')
        ? ownerWorkflowSource(version)
        : readFileSync(new URL('../../'+path,import.meta.url),'utf8').replace(/\r\n/g,'\n');
    if (path.endsWith('/candidate-preparation.yml')) {
        source=source.replace(/php scripts\/audit\.php --profile=basic(?: --changed)?/g,
            'php scripts/audit.php --profile=basic'+(version>=2 ? ' --changed' : ''));
    }
    const bytes=Buffer.from(source);
    const header=Buffer.from('blob '+bytes.length+'\0');
    const sha=createHash('sha1').update(Buffer.concat([header,bytes])).digest('hex');
    return {type:'file',path,sha,encoding:'base64',size:bytes.length,content:bytes.toString('base64')};
}
