/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/gallery_benchmark_runtime_scope_test.mjs
 * Module Type: Test
 *
 * Purpose:
 *   Executes the benchmark lightbox scenario far enough to prove that its
 *   cleanup variables exist in the same function scope as the finally block.
 */

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const sourcePath = path.join(here, '..', 'public', 'assets', 'gallery-modules', 'admin-gallery-benchmark.js');
let source = fs.readFileSync(sourcePath, 'utf8');
source = source.replace('export function setupAdminGalleryBenchmark()', 'function setupAdminGalleryBenchmark()');
source += '\n;globalThis.__benchmarkRuntimeScopeProbe = runBenchmarkLightboxScenario;\n';

const context = vm.createContext({
    console,
    URL,
    Date,
    FormData,
    performance,
    setTimeout,
    clearTimeout,
    window: {setTimeout, clearTimeout},
});
vm.runInContext(source, context, {filename: 'admin-gallery-benchmark.js'});

const result = await context.__benchmarkRuntimeScopeProbe(
    {contentWindow: null, contentDocument: null},
    'https://example.invalid/gallery/',
    'token',
    1,
    '/static-probe.txt',
    '/php-probe'
);

if (!result || result.reason !== 'same_origin_iframe_unavailable') {
    throw new Error(`Unexpected benchmark runtime-scope probe result: ${JSON.stringify(result)}`);
}

console.log('Gallery benchmark v4.2 runtime scope test passed.');
