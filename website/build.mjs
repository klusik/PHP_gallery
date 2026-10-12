/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: website/build.mjs
 * Module Type: Static Website Builder
 * Purpose: Render four language variants against one published release identity.
 * Responsibilities: Validate release provenance, create static navigation and copy only reviewed assets.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {readFileSync, writeFileSync, mkdirSync, copyFileSync, existsSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import {resolve, dirname} from 'node:path';
import {fileURLToPath} from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const output = resolve(root, process.env.PHP_GALLERY_WEBSITE_OUTPUT || 'cache/website-output');
const content = JSON.parse(readFileSync(resolve(root, 'website/content.json'), 'utf8'));
const releaseFile = process.env.PHP_GALLERY_WEBSITE_RELEASE;
if (!releaseFile) throw new Error('Supply PHP_GALLERY_WEBSITE_RELEASE with published GitHub release JSON.');
const release = JSON.parse(readFileSync(releaseFile, 'utf8'));
if (!/^v_\d+\.\d+(?:\.\d+)?$/.test(release.tag_name) || release.draft !== false || release.prerelease !== false
    || typeof release.published_at !== 'string' || !Number.isFinite(Date.parse(release.published_at))
    || release.html_url !== `https://github.com/klusik/PHP_gallery/releases/tag/${release.tag_name}`) {
    throw new Error('Website requires a published, stable PHP Gallery release with a canonical URL.');
}
const commit = execFileSync('git', ['rev-parse', `${release.tag_name}^{commit}`], {cwd:root, encoding:'utf8'}).trim();
if (!/^[a-f0-9]{40}$/.test(commit)) throw new Error('Release tag did not resolve to a full commit identity.');
const repository = 'https://github.com/klusik/PHP_gallery';
const source = `${repository}/blob/${commit}`;
const manualFiles = {en:'PHP_Gallery_Manual.pdf', cs:'PHP_Gallery_Manual_CZ.pdf', de:'PHP_Gallery_Manual_DE.pdf', sv:'PHP_Gallery_Manual_SV.pdf'};
for (const file of ['README.md', 'PATCH_NOTES.md', ...Object.values(manualFiles).map(name => `docs/${name}`)]) {
    execFileSync('git', ['cat-file', '-e', `${commit}:${file}`], {cwd:root});
}
// Output must be a fresh directory, avoiding retained files from earlier publication.
if (existsSync(output)) throw new Error('Website output already exists; select a new empty output directory.');
mkdirSync(output, {recursive:true});
mkdirSync(resolve(output, 'assets'));
for (const asset of ['site.css', 'gallery-preview.svg', 'ofp-description-actions.webp']) {
    copyFileSync(resolve(root, 'website/assets', asset), resolve(output, 'assets', asset));
}
writeFileSync(resolve(output, '.nojekyll'), '');
writeFileSync(resolve(output, 'release.json'), JSON.stringify({tag:release.tag_name,commit,url:release.html_url,published:release.published_at}, null, 2) + '\n');

/** Escape authored or release text for an HTML text or quoted attribute context.
 * @param {string} value Text inserted into generated HTML.
 * @returns {string} Text with HTML metacharacters escaped.
 */
function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
}

/** Return the project-relative route for a locale and document kind.
 * @param {string} locale Supported content locale, with English at the project root.
 * @param {string} page Document kind: home or docs.
 * @returns {string} Directory route under /PHP_gallery/.
 */
function route(locale, page) {
    return `${locale === 'en' ? '' : locale + '/'}${page === 'docs' ? 'docs/' : ''}`;
}

/** Render release identity and hosting requirements in a shared information strip.
 * @param {string} locale Supported content locale.
 * @returns {string} Accessible strip markup with a canonical release link.
 */
function releaseStrip(locale) {
    const t = content[locale];
    return `<div class="release-strip"><span><span class="status-dot" aria-hidden="true"></span>${escapeHtml(t.release)} <a href="${escapeHtml(release.html_url)}">${escapeHtml(release.tag_name.replace('v_', 'v'))} ↗</a></span><span>${escapeHtml(t.requirements)}</span><span>${escapeHtml(t.license)}</span></div>`;
}

/** Render the complete project presentation body from one authored translation.
 * @param {string} locale Supported content locale.
 * @returns {string} Homepage main-content markup with privacy-safe reviewed imagery.
 */
function home(locale) {
    const t = content[locale];
    const prefix = locale === 'en' ? './' : '../';
    const docs = `${prefix}${route(locale, 'docs')}`;
    const manuals = docs + '#manuals';
    return `<section class="hero"><div><p class="eyebrow">${escapeHtml(t.eyebrow)}</p><h1>${escapeHtml(t.title)}</h1><p class="lead">${escapeHtml(t.lead)}</p><div class="actions"><a class="button primary" href="${release.html_url}">${escapeHtml(t.download)} ↗</a><a class="text-link" href="${docs}">${escapeHtml(t.heroLink)} →</a></div></div><figure class="hero-art"><img src="${prefix}assets/gallery-preview.svg" width="720" height="620" alt="${escapeHtml(t.illustration)}"><figcaption>${escapeHtml(t.illustration)}</figcaption></figure></section>
    ${releaseStrip(locale)}
    <section class="section" id="capabilities"><div class="section-heading"><h2>${escapeHtml(t.intro)}</h2><p>${escapeHtml(t.introText)}</p></div><div class="capabilities">${t.items.map(([number,title,text]) => `<article class="capability"><span class="number">${number}</span><h3>${escapeHtml(title)}</h3><p>${escapeHtml(text)}</p></article>`).join('')}</div></section>
    <section class="section story"><div><p class="eyebrow">EXIF / GPS / SIMBRIEF</p><h2>${escapeHtml(t.showcase)}</h2><p>${escapeHtml(t.showcaseText)}</p><div class="actions"><a class="text-link" href="${manuals}">${escapeHtml(t.manuals)} →</a></div></div><figure class="screen-detail"><img src="${prefix}assets/ofp-description-actions.webp" width="1600" height="233" loading="lazy" alt="${escapeHtml(t.screenshot)}"><figcaption>${escapeHtml(t.screenshot)}</figcaption></figure></section>
    <section class="section foundation"><div class="code-panel"><span>01 / STORAGE</span><br>galleries/ → originals<br><span>02 / METADATA</span><br>MySQL / MariaDB<br><span>03 / RUNTIME</span><br>PHP 8.1+ · HTML · CSS · JavaScript</div><div><h2>${escapeHtml(t.technical)}</h2><p>${escapeHtml(t.technicalText)}</p><div class="actions"><a class="text-link" href="${source}/README.md">${escapeHtml(t.readme)} ↗</a></div></div></section>
    <section class="cta"><div><h2>${escapeHtml(t.start)}</h2><p>${escapeHtml(t.startText)}</p></div><a class="button primary" href="${docs}">${escapeHtml(t.getting)} →</a></section>`;
}

/** Render the documentation entry point using links pinned to the selected release.
 * @param {string} locale Supported content locale.
 * @returns {string} Documentation main-content markup with all four manuals and release references.
 */
function documentation(locale) {
    const t = content[locale];
    return `<header class="docs-hero"><p class="eyebrow">PHP GALLERY / ${escapeHtml(t.docs)}</p><h1>${escapeHtml(t.docsTitle)}</h1><p class="lead">${escapeHtml(t.docsLead)}</p></header>${releaseStrip(locale)}
    <nav class="tabs" aria-label="${escapeHtml(t.docs)}"><a href="#getting-started">${escapeHtml(t.getting)}</a><a href="#manuals">${escapeHtml(t.manuals)}</a><a href="#reference">${escapeHtml(t.reference)}</a></nav>
    <section class="section" id="getting-started"><h2>${escapeHtml(t.getting)}</h2><div class="steps">${t.steps.map(([title,text], index) => `<article><span class="number">0${index + 1}</span><h3>${escapeHtml(title)}</h3><p>${escapeHtml(text)}</p></article>`).join('')}</div><a class="button" href="${source}/README.md#installation">${escapeHtml(t.install)} ↗</a></section>
    <section class="section" id="manuals"><h2>${escapeHtml(t.manuals)}</h2><p style="margin-top:20px">${escapeHtml(t.manualsText)}</p><div class="manual-list">${Object.entries(manualFiles).map(([language,file]) => `<a class="manual" href="https://raw.githubusercontent.com/klusik/PHP_gallery/${commit}/docs/${file}" hreflang="${language}"><strong lang="${language}">${escapeHtml(content[language].name)}</strong><small>${escapeHtml(t.pdf)} ↓ · ${escapeHtml(release.tag_name.replace('v_', 'v'))}</small></a>`).join('')}</div></section>
    <section class="section" id="reference"><h2>${escapeHtml(t.reference)}</h2><div class="references"><article><h3><a href="${source}/README.md">${escapeHtml(t.readme)} ↗</a></h3><p>${escapeHtml(t.readmeText)}</p></article><article><h3><a href="${source}/PATCH_NOTES.md">${escapeHtml(t.notes)} ↗</a></h3><p>${escapeHtml(t.notesText)}</p></article><article><h3><a href="${release.html_url}">${escapeHtml(t.releasePage)} ↗</a></h3><p class="tag">${escapeHtml(release.tag_name)} · ${escapeHtml(release.published_at.slice(0,10))}</p><a href="${repository}/tree/${commit}">${escapeHtml(t.source)} ↗</a></article></div><p class="note">${escapeHtml(t.versioned)}</p></section><p class="runtime-note">${escapeHtml(t.runtime)}</p>`;
}

/** Wrap localized content with static navigation, SEO metadata and locale-preserving links.
 * @param {string} locale Supported content locale.
 * @param {string} page Document kind: home or docs.
 * @param {string} body Prepared main-content HTML generated from escaped authored text.
 * @returns {string} Complete HTML5 document.
 */
function document(locale, page, body) {
    const t = content[locale];
    const prefix = '../'.repeat((locale === 'en' ? 0 : 1) + (page === 'docs' ? 1 : 0)) || './';
    const title = `PHP Gallery — ${page === 'home' ? t.title : t.docs}`;
    const canonical = `https://klusik.github.io/PHP_gallery/${route(locale,page)}`;
    const reference = prefix + route(locale,'docs') + '#reference';
    return `<!doctype html>
<html lang="${locale}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${escapeHtml(title)}</title><meta name="description" content="${escapeHtml(page === 'home' ? t.lead : t.docsLead)}"><meta name="theme-color" content="#f5f5ef"><link rel="canonical" href="${canonical}">${Object.keys(content).map(language => `<link rel="alternate" hreflang="${language}" href="https://klusik.github.io/PHP_gallery/${route(language,page)}">`).join('')}<link rel="alternate" hreflang="x-default" href="https://klusik.github.io/PHP_gallery/${route('en',page)}"><link rel="stylesheet" href="${prefix}assets/site.css"><meta property="og:title" content="${escapeHtml(title)}"><meta property="og:description" content="${escapeHtml(page === 'home' ? t.lead : t.docsLead)}"><meta property="og:url" content="${canonical}"><meta property="og:type" content="website"></head>
<body><a class="skip" href="#main">${escapeHtml(t.skip)}</a><div class="wrap"><header class="masthead"><a class="brand" href="${prefix}${route(locale,'home')}"><span class="mark" aria-hidden="true">g.</span>PHP Gallery</a><nav aria-label="${escapeHtml(t.home)}"><a href="${prefix}${route(locale,'home')}"${page === 'home' ? ' aria-current="page"' : ''}>${escapeHtml(t.home)}</a><a href="${prefix}${route(locale,'docs')}"${page === 'docs' ? ' aria-current="page"' : ''}>${escapeHtml(t.docs)}</a><a class="nav-github" href="${repository}">GitHub ↗</a><details class="languages"><summary aria-label="${escapeHtml(t.language)}: ${escapeHtml(t.name)}">${locale.toUpperCase()}</summary><div class="language-list">${Object.keys(content).map(language => `<a lang="${language}" hreflang="${language}" href="${prefix}${route(language,page)}"${language === locale ? ' aria-current="true"' : ''}>${escapeHtml(content[language].name)}</a>`).join('')}</div></details></nav></header><main id="main" tabindex="-1">${body}</main><footer><p>${escapeHtml(t.footer)}</p><div><a href="${repository}">GitHub</a> · <a href="${source}/LICENSE">MIT</a> · <a href="${reference}">${escapeHtml(t.notes)}</a></div></footer></div></body></html>\n`;
}

for (const locale of Object.keys(content)) {
    for (const page of ['home','docs']) {
        const directory = resolve(output, route(locale,page));
        mkdirSync(directory, {recursive:true});
        writeFileSync(resolve(directory,'index.html'), document(locale,page,page === 'home' ? home(locale) : documentation(locale)));
    }
}
console.log(`Website built: ${output}; release ${release.tag_name}; release commit ${commit}`);
