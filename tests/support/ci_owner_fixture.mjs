/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/ci_owner_fixture.mjs
 * Module Type: Inert Workflow Identity Fixture
 * Purpose: Supply complete hashed current and historical workflow sources to the real owner validator.
 * Responsibilities: Preserve explicit immutable API paths and exercise legacy PHP 8.3 evidence without external writes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {readFileSync} from 'node:fs';
import {createHash} from 'node:crypto';

/**
 * Produce a complete Contents response for one reviewed immutable workflow fixture request.
 * @param {string} request Literal API request path.
 * @param {1|2} [version=2] Historical or current owner/BASIC invocation contract.
 * @returns {{type:string,path:string,sha:string,encoding:string,size:number,content:string}|null} Complete Git-hashed source response, or null for an unrelated endpoint.
 */
export function ciOwnerWorkflowResponse(request,version=2) {
    const match=/\/contents\/(\.github\/workflows\/(?:gallery-workflows|candidate-preparation)\.yml)\?ref=[a-f0-9]{40}$/.exec(request);
    if (!match) return null;
    const path=match[1];
    let source=readFileSync(new URL('../../'+path,import.meta.url),'utf8').replace(/\r\n/g,'\n');
    if (path.endsWith('/candidate-preparation.yml')) {
        source=source.replace(/php scripts\/audit\.php --profile=basic(?: --changed)?/g,
            'php scripts/audit.php --profile=basic'+(version===2 ? ' --changed' : ''));
    }
    if (version===1) {
        source=path.endsWith('/gallery-workflows.yml')
            ? source.replace(/php: '8\.5'(\n            database: '(?:mysql:8\.4|mariadb:10\.11)')/g,"php: '8.3'$1")
            : source.replace('php scripts/audit.php --profile=basic --changed','php scripts/audit.php --profile=basic');
    }
    const bytes=Buffer.from(source);
    const sha=createHash('sha1').update(Buffer.concat([Buffer.from(`blob ${bytes.length}\0`),bytes])).digest('hex');
    return {type:'file',path,sha,encoding:'base64',size:bytes.length,content:bytes.toString('base64')};
}
