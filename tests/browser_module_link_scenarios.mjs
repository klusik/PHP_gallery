/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/browser_module_link_scenarios.mjs
 * Module Type: Regression Test
 * Purpose: Link the real browser entry graph and protect scoped PDF helper cache identity.
 * Responsibilities: Detect missing named exports without replacing imports or executing UI code.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import vm from 'node:vm';

if (typeof vm.SourceTextModule !== 'function') {
    const result = spawnSync(process.execPath, ['--experimental-vm-modules', fileURLToPath(import.meta.url)], {encoding: 'utf8'});
    process.stdout.write(result.stdout || '');
    process.stderr.write(result.stderr || '');
    assert.equal(result.status, 0, 'Real browser module linking must succeed');
} else {
    const entry = new URL('../public/assets/gallery.js', import.meta.url);
    const context = vm.createContext({});
    const modules = new Map();
    /**
     * Compile one real browser module while preserving its query-string identity.
     * @param {URL} url Absolute first-party asset URL.
     * @returns {Promise<import('node:vm').SourceTextModule>} Cached compiled module, without UI evaluation.
     */
    async function compileModule(url) {
        if (!modules.has(url.href)) {
            const pending = readFile(url, 'utf8').then((source) => new vm.SourceTextModule(source, {context, identifier: url.href}));
            modules.set(url.href, pending);
        }
        return modules.get(url.href);
    }
    const module = await compileModule(entry);
    await module.link((specifier, referencing) => compileModule(new URL(specifier, referencing.identifier)));
    assert.equal(module.status, 'linked', 'Production entry graph resolves every static named import');

    const pdf = await readFile(new URL('./gallery-modules/admin-pdf-documents.js', entry), 'utf8');
    const upload = await readFile(new URL('./gallery-modules/admin-browser-upload.js', entry), 'utf8');
    const gallery = await readFile(entry, 'utf8');
    const pdfCore = pdf.match(/from '(\.\/admin-core\.js\?v=[^']+)'/);
    const uploadCore = upload.match(/from '(\.\/admin-core\.js\?v=[^']+)'/);
    assert.ok(pdfCore && uploadCore, 'PDF and uploader use versioned shared helpers');
    assert.equal(pdfCore[1], uploadCore[1], 'PDF scoped translations reuse the uploader helper cache identity');
    const dynamicPdf = upload.match(/import\('\.\/admin-pdf-documents\.js\?v=([^']+)'\)/);
    const staticPdf = gallery.match(/from '\.\/gallery-modules\/admin-pdf-documents\.js\?v=([^']+)'/);
    assert.ok(dynamicPdf && staticPdf, 'Both PDF module entry paths retain cache versions');
    assert.equal(dynamicPdf[1], staticPdf[1], 'Static and on-demand PDF imports share the reviewed module version');
    console.log(`Real browser module graph linked (${modules.size} module URLs); PDF cache contracts passed.`);
}
