/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/project_website_test.mjs
 * Module Type: Static Website Regression
 * Purpose: Qualify the website artifact, project-prefix routes and native keyboard navigation.
 * Responsibilities: Reject unintended files, verify release-pinned references and exercise desktop/mobile Chromium.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFileSync, readdirSync, existsSync} from 'node:fs';
import {resolve, dirname, extname, sep} from 'node:path';
import {fileURLToPath} from 'node:url';
import {createServer} from 'node:http';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';
import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
import {mkdir, mkdtemp, rm, writeFile} from 'node:fs/promises';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const output = resolve(root, process.env.PHP_GALLERY_WEBSITE_OUTPUT || 'cache/website-output');
const executable = process.argv[2];
const execute = promisify(execFile);
assert.ok(executable, 'Website suite requires an installed Chromium runtime.');
const release = JSON.parse(readFileSync(resolve(output, 'release.json'), 'utf8'));
assert.match(release.commit, /^[a-f0-9]{40}$/);
assert.equal(release.url, `https://github.com/klusik/PHP_gallery/releases/tag/${release.tag}`);
const pages = ['index.html','docs/index.html','cs/index.html','cs/docs/index.html','de/index.html','de/docs/index.html','sv/index.html','sv/docs/index.html'];
const allowed = [...pages, '.nojekyll','release.json','assets/site.css','assets/gallery-preview.svg','assets/ofp-description-actions.webp'].sort();
const actual = readdirSync(output, {recursive:true,withFileTypes:true}).filter(entry => entry.isFile()).map(entry => resolve(entry.parentPath,entry.name).slice(output.length+1).replaceAll('\\','/')).sort();
assert.deepEqual(actual,allowed,'Pages artifact must contain only the explicit website surface.');
const locales = JSON.parse(readFileSync(resolve(root,'website/content.json'),'utf8'));
for (const locale of Object.values(locales)) {
    assert.deepEqual(Object.keys(locale).sort(),Object.keys(locales.en).sort(),'Every translated field must exist.');
    assert.equal(locale.items.length,4);
    assert.equal(locale.steps.length,3);
}
for (const page of pages) {
    const html = readFileSync(resolve(output,page),'utf8');
    const locale = page.startsWith('cs/') ? 'cs' : page.startsWith('de/') ? 'de' : page.startsWith('sv/') ? 'sv' : 'en';
    assert.ok(html.includes(`<html lang="${locale}">`));
    assert.ok(html.includes(release.url));
    assert.ok(html.includes(`/blob/${release.commit}/README.md`));
    assert.equal((html.match(/<h1>/g)||[]).length,1);
    assert.ok(!html.includes('<script'),'Navigation must not depend on JavaScript.');
    for (const [,attribute,value] of html.matchAll(/\b(href|src)="([^"]+)"/g)) {
        const target = new URL(value,`https://example.test/PHP_gallery/${page}`);
        if (target.origin !== 'https://example.test') continue;
        assert.ok(target.pathname.startsWith('/PHP_gallery/'),'Local URL must stay below the project prefix.');
        const relative = decodeURIComponent(target.pathname.slice('/PHP_gallery/'.length));
        const file = resolve(output,relative.endsWith('/') ? relative+'index.html' : relative);
        assert.ok(existsSync(file),`${page}: ${attribute} target missing: ${value}`);
        if (target.hash) assert.ok(readFileSync(file,'utf8').includes(`id="${target.hash.slice(1)}"`),`Missing fragment ${value}`);
    }
    if (page.includes('docs/')) {
        assert.ok(html.includes(`/blob/${release.commit}/PATCH_NOTES.md`));
        for (const suffix of ['', '_CZ','_DE','_SV']) assert.ok(html.includes(`/docs/PHP_Gallery_Manual${suffix}.pdf`));
    }
}
const styles = readFileSync(resolve(output,'assets/site.css'),'utf8');
assert.ok(styles.includes(':focus-visible'));
assert.ok(styles.includes('prefers-reduced-motion:reduce'));
// Publication must fail closed when GitHub metadata does not describe a stable release.
const invalidReleaseDirectory = await mkdtemp(resolve(root,'cache/website-release-refusal-'));
try {
    const metadata = resolve(invalidReleaseDirectory,'release.json');
    const refusedOutput = resolve(invalidReleaseDirectory,'output');
    for (const override of [{draft:true},{prerelease:true},{published_at:'invalid'},
        {html_url:'https://example.test/untrusted-release'}]) {
        await writeFile(metadata,JSON.stringify({tag_name:release.tag,html_url:release.url,
            published_at:release.published,draft:false,prerelease:false,...override}));
        await assert.rejects(execute(process.execPath,[resolve(root,'website/build.mjs')],{
            cwd:root,env:{...process.env,PHP_GALLERY_WEBSITE_RELEASE:metadata,PHP_GALLERY_WEBSITE_OUTPUT:refusedOutput},
            timeout:10000,windowsHide:true}),/published, stable PHP Gallery release/);
        assert.equal(existsSync(refusedOutput),false,'Refused release metadata must not create a publishable artifact.');
    }
} finally {
    if (dirname(invalidReleaseDirectory) === resolve(root,'cache')
        && invalidReleaseDirectory.startsWith(resolve(root,'cache/website-release-refusal-'))) {
        await rm(invalidReleaseDirectory,{recursive:true,force:true});
    }
}
const server = createServer((request,response) => {
    const url = new URL(request.url, 'http://127.0.0.1');
    const relative = decodeURIComponent(url.pathname).replace(/^\/PHP_gallery\//,'');
    const file = relative.endsWith('/') || relative === '' ? relative+'index.html' : relative;
    if (!url.pathname.startsWith('/PHP_gallery/') || !allowed.includes(file)) {
        response.writeHead(404).end(); return;
    }
    const types = {'.html':'text/html; charset=utf-8','.css':'text/css','.svg':'image/svg+xml','.webp':'image/webp','.json':'application/json'};
    response.writeHead(200,{'Content-Type':types[extname(file)] || 'text/plain'});
    response.end(readFileSync(resolve(output,file)));
});
await new Promise(done => server.listen(0,'127.0.0.1',done));
try {
    const screenshots = process.env.PHP_GALLERY_WEBSITE_SCREENSHOTS
        ? resolve(root,process.env.PHP_GALLERY_WEBSITE_SCREENSHOTS) : null;
    const screenshotFiles = [];
    if (screenshots) {
        assert.ok(screenshots.startsWith(resolve(root,'cache') + sep),'Screenshot evidence must stay in ignored cache.');
        await mkdir(screenshots,{recursive:true});
    }
    for (const width of [1280,390,320]) {
        for (const page of pages) {
            let marker = '';
            const url = `http://127.0.0.1:${server.address().port}/PHP_gallery/${page.replace('index.html','')}`;
            const result = await runHeadlessBrowserFixture(executable,url,'website-fixture-',{
                viewport:{width,height:900}, readResult:async () => marker,
                interact:async ({evaluate,key,captureScreenshot}) => {
                    // The shared fixture admits interactive documents before stylesheet/image
                    // completion. Measure only the fully loaded website, not its initial paint.
                    await evaluate(`document.readyState === 'complete' ? document.fonts.ready : new Promise(resolve => addEventListener('load', () => document.fonts.ready.then(resolve), {once:true}))`);
                    await evaluate(`Promise.all([...document.images].map(image=>{image.loading='eager';return image.decode()}))`);
                    const state = await evaluate(`({overflow:document.documentElement.scrollWidth>innerWidth,heading:document.querySelector('h1').textContent,styles:getComputedStyle(document.querySelector('h1')).fontFamily})`);
                    assert.equal(state.overflow,false,`${page} at ${width}px must not scroll horizontally.`);
                    assert.ok(state.heading.length > 10);
                    assert.ok(state.styles.includes('Georgia'),`${page} at ${width}px: website heading font was ${state.styles}`);
                    const readable = await evaluate(`(() => {
                        const luminance = color => {
                            const rgb = color.match(/[\\d.]+/g).slice(0,3).map(Number).map(value => {
                                const channel = value / 255;
                                return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
                            });
                            return rgb[0]*0.2126 + rgb[1]*0.7152 + rgb[2]*0.0722;
                        };
                        return [...document.querySelectorAll('.lead,.eyebrow,figcaption,.button.primary')].every(element => {
                            let parent = element;
                            while (getComputedStyle(parent).backgroundColor === 'rgba(0, 0, 0, 0)') parent = parent.parentElement;
                            const foreground = luminance(getComputedStyle(element).color);
                            const background = luminance(getComputedStyle(parent).backgroundColor);
                            return (Math.max(foreground,background)+0.05)/(Math.min(foreground,background)+0.05) >= 4.5;
                        });
                    })()`);
                    assert.equal(readable,true,'Primary copy, captions and buttons must meet 4.5:1 contrast.');
                    const screenshotPages = width === 320 ? ['de/index.html','de/docs/index.html'] : ['index.html','docs/index.html'];
                    if (screenshots && screenshotPages.includes(page)) {
                        // Use the loaded, asserted document instead of the CLI startup that
                        // stalled in hosted run 38230411649. Capture before keyboard disclosure.
                        const png = Buffer.from(await captureScreenshot(),'base64');
                        assert.equal(png.subarray(0,8).toString('hex'),'89504e470d0a1a0a','Screenshot evidence must be PNG.');
                        assert.equal(png.readUInt32BE(16),width,'PNG width must match the tested CSS viewport.');
                        assert.ok(png.readUInt32BE(20)>=900,'Screenshot must cover the full document, including the viewport.');
                        const name = page.startsWith('de/') ? (page.includes('docs/') ? 'de-docs' : 'de-home')
                            : (page.includes('docs/') ? 'docs' : 'home');
                        const filename = `${name}-${width}.png`;
                        await writeFile(resolve(screenshots,filename),png);
                        screenshotFiles.push(filename);
                    }
                    await key('keyDown','Tab'); await key('keyUp','Tab');
                    assert.equal(await evaluate(`document.activeElement.className`),'skip');
                    assert.equal(await evaluate(`getComputedStyle(document.activeElement).outlineStyle`),'solid');
                    await evaluate(`document.querySelector('.languages summary').focus()`);
                    await key('keyDown','Enter'); await key('keyUp','Enter');
                    assert.equal(await evaluate(`document.querySelector('.languages').open`),true);
                    assert.equal(await evaluate(`document.querySelectorAll('.language-list a').length`),4);
                    const samePage = page.includes('docs/') ? '/docs/' : '/';
                    assert.ok(await evaluate(`[...document.querySelectorAll('.language-list a')].every(a=>new URL(a.href).pathname.endsWith(${JSON.stringify(samePage)}))`),'Language links must preserve document kind.');
                    marker = 'BROWSER PASS website route, viewport, assets and keyboard';
                }
            });
            assert.equal(result.result,marker);
        }
    }
    if (screenshots) assert.deepEqual(screenshotFiles.sort(),[
        'home-1280.png','docs-1280.png','home-390.png','docs-390.png','de-home-320.png','de-docs-320.png',
    ].sort(),'Every requested desktop/mobile screenshot must be retained.');
    console.log('PASS website-only artifact; release-bound README, patch notes and four PDFs; eight project routes; desktop/390px/320px layouts; native keyboard language disclosure and focus.');
} finally {
    await new Promise(done => server.close(done));
}
