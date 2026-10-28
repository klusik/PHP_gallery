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
/** @type {string} Production origin shared by canonical, language and share metadata. */
const siteBaseUrl = 'https://phpgallery.site';
const source = `${repository}/blob/${commit}`;
const manualFiles = {en:'PHP_Gallery_Manual.pdf', cs:'PHP_Gallery_Manual_CZ.pdf', de:'PHP_Gallery_Manual_DE.pdf', sv:'PHP_Gallery_Manual_SV.pdf'};
for (const file of ['README.md', 'PATCH_NOTES.md', ...Object.values(manualFiles).map(name => `docs/${name}`)]) {
    execFileSync('git', ['cat-file', '-e', `${commit}:${file}`], {cwd:root});
}

/** @type {ReadonlyArray<{id:string,file:string,width:number,height:number,source:string}>} Reviewed unaltered captures; dimensions describe the original JPEG pixels. */
const screenshots = Object.freeze([
    {id:'gallery', file:'real-gallery.jpg', width:1353, height:874,
        source:'https://galerie.klusik.cz/gallery/game-screens/flight-simulators/'},
    {id:'viewer', file:'real-lightbox.jpg', width:1280, height:720,
        source:'https://galerie.klusik.cz/gallery/game-screens/flight-simulators/a320/westbound-tour-leg-36-from-kitakyushu-airport-to-shanghai-pudong-international-airport-rjfr-zspd/'},
    {id:'music', file:'real-music-admin.jpg', width:1265, height:712,
        source:'https://github.com/klusik/PHP_gallery/blob/b9bf3f211059cfd70523fbf4a2cde0d02f2f17bd/docs/GALLERY_MUSIC.md'},
    {id:'map', file:'real-map.jpg', width:1368, height:884,
        source:'https://galerie.klusik.cz/gallery/game-screens/flight-simulators/a320/westbound-tour-leg-36-from-kitakyushu-airport-to-shanghai-pudong-international-airport-rjfr-zspd/'}
]);

/** @type {string} Authored local SVG symbols shared by all generated routes. */
const iconDefinitions = `<svg class="icon-definitions" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <symbol id="i-arrow" viewBox="0 0 24 24"><path d="M4 12h15m-6-6 6 6-6 6"/></symbol>
        <symbol id="i-external" viewBox="0 0 24 24"><path d="M14 4h6v6m0-6L10 14m0-10H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-5"/></symbol>
        <symbol id="i-download" viewBox="0 0 24 24"><path d="M12 3v12m-5-5 5 5 5-5M4 16v4h16v-4"/></symbol>
        <symbol id="i-photo" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="m3 16 5-5 4 4 3-3 6 6"/><circle cx="15" cy="9" r="1.5"/></symbol>
        <symbol id="i-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></symbol>
        <symbol id="i-expand" viewBox="0 0 24 24"><path d="M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5"/></symbol>
        <symbol id="i-sliders" viewBox="0 0 24 24"><path d="M4 7h7m6 0h3M4 17h2m6 0h8"/><circle cx="14" cy="7" r="3"/><circle cx="9" cy="17" r="3"/></symbol>
        <symbol id="i-search" viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></symbol>
        <symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
        <symbol id="i-lock" viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/></symbol>
        <symbol id="i-map" viewBox="0 0 24 24"><path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3V6Zm6-3v15m6-12v15"/></symbol>
        <symbol id="i-music" viewBox="0 0 24 24"><path d="M9 17V5l11-2v12M9 8l11-2"/><ellipse cx="6" cy="18" rx="3" ry="2.5"/><ellipse cx="17" cy="16" rx="3" ry="2.5"/></symbol>
        <symbol id="i-folder" viewBox="0 0 24 24"><path d="M3 7V4h7l2 3h9v13H3V7Z"/></symbol>
        <symbol id="i-share" viewBox="0 0 24 24"><path d="M12 15V3m-4 4 4-4 4 4M5 11v10h14V11"/></symbol>
        <symbol id="i-book" viewBox="0 0 24 24"><path d="M12 5v16M3 3c4 0 6 0 9 2 3-2 5-2 9-2v16c-4 0-6 0-9 2-3-2-5-2-9-2V3Z"/></symbol>
        <symbol id="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/></symbol>
        <symbol id="i-moon" viewBox="0 0 24 24"><path d="M20.5 14A9 9 0 0 1 10 3.5 9 9 0 1 0 20.5 14Z"/></symbol>
        <symbol id="i-code" viewBox="0 0 24 24"><path d="m8 6-6 6 6 6m8-12 6 6-6 6m-3-15-2 18"/></symbol>
        <symbol id="i-close" viewBox="0 0 24 24"><path d="m5 5 14 14M5 19 19 5"/></symbol>
        <symbol id="i-play" viewBox="0 0 24 24"><path d="m8 4 12 8-12 8V4Z"/></symbol>
        <symbol id="i-chevron" viewBox="0 0 24 24"><path d="m9 5 7 7-7 7"/></symbol>
    </svg>`;

// Output must be a fresh directory, avoiding retained files from earlier publication.
if (existsSync(output)) throw new Error('Website output already exists; select a new empty output directory.');
mkdirSync(output, {recursive:true});
mkdirSync(resolve(output, 'assets'));
for (const asset of ['site.css', 'site.js', ...screenshots.map(image => image.file)]) {
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

/** Return the site-relative route for a locale and document kind.
 * @param {string} locale Supported content locale, with English at the site root.
 * @param {string} page Document kind: home or docs.
 * @returns {string} Directory route relative to the site root or preview mount.
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

/**
 * Render one decorative icon from the authored document-local symbol set.
 * @param {string} name Known icon identifier without its i- prefix.
 * @returns {string} Decorative SVG markup excluded from accessible names.
 */
function icon(name) {
    return `<svg class="icon" aria-hidden="true"><use href="#i-${escapeHtml(name)}"/></svg>`;
}

/**
 * Render an authentic screenshot with an ordinary full-image link fallback.
 * @param {string} locale Supported content locale.
 * @param {number} index Index in the reviewed screenshot and translated label lists.
 * @param {string} prefix Relative project-root path for the current page.
 * @returns {string} Complete image anchor and source metadata for optional enlargement.
 */
function capture(locale, index, prefix) {
    const image = screenshots[index];
    const label = content[locale].captures[index];
    return `<a class="actual-screen-button" href="${prefix}assets/${image.file}"
        data-screenshot data-capture-id="${image.id}"
        data-title="${escapeHtml(label.title)}" data-source="${image.source}"
        data-source-label="${escapeHtml(label.sourceLabel)}"
        aria-label="${escapeHtml(content[locale].expandCapture + ': ' + label.title)}">
        <img src="${prefix}assets/${image.file}" width="${image.width}"
            height="${image.height}" alt="${escapeHtml(label.alt)}"
            ${index === 0 ? 'fetchpriority="high"' : 'loading="lazy"'}></a>`;
}

/**
 * Render the approved presentation with real application captures and native tabs.
 * @param {string} locale Supported content locale.
 * @returns {string} Homepage markup; navigation and screenshot links need no script.
 */
function home(locale) {
    const t = content[locale];
    const prefix = locale === 'en' ? './' : '../';
    const docs = prefix + route(locale, 'docs');
    return `<section class="hero wrap" aria-labelledby="hero-title">
        <div class="hero-copy">
            <p class="eyebrow"><span class="little-star" aria-hidden="true">✳</span>${escapeHtml(t.eyebrow)}</p>
            <h1 id="hero-title">${escapeHtml(t.heroFirst)}<br><span class="quiet-heading">${escapeHtml(t.heroSecond)}</span></h1>
            <p class="lead hero-lead">${escapeHtml(t.lead)}</p>
            <div class="actions hero-actions">
                <a class="button primary" href="${release.html_url}">${icon('download')}${escapeHtml(t.download)}</a>
                <a class="text-link" href="#showcase">${escapeHtml(t.heroLink)} ${icon('arrow')}</a>
            </div>
            <p class="hero-footnote">${icon('check')}${escapeHtml(t.heroFootnote)}</p>
        </div>
        <figure class="product-preview" id="showcase">
            <div class="preview-backdrop" aria-hidden="true"></div>
            <div class="preview-window glass">
                <div class="preview-titlebar"><span class="preview-brand"><span class="mini-mark">g.</span> PHP Gallery</span><span class="preview-status">${escapeHtml(t.captureVersion)}</span></div>
                <fieldset class="capture-tabs"><legend class="visually-hidden">${escapeHtml(t.previewTabs)}</legend>
                    ${screenshots.slice(0,3).map((image,index) => `<input class="capture-choice" type="radio" name="capture" id="capture-${image.id}"${index === 0 ? ' checked' : ''}><label for="capture-${image.id}">${icon(['grid','expand','music'][index])}${escapeHtml(t.captures[index].tab)}</label>`).join('\n')}
                    ${screenshots.slice(0,3).map((image,index) => `<div class="screenshot-panel" id="panel-${image.id}">${capture(locale,index,prefix)}<div class="screen-source-line"><span>${escapeHtml(t.captures[index].caption)}</span><a href="${image.source}" target="_blank" rel="noopener noreferrer">${escapeHtml(t.captures[index].sourceLabel)} ${icon('external')}</a></div></div>`).join('\n')}
                </fieldset>
            </div>
            <figcaption>${escapeHtml(t.captureCaption)}</figcaption>
        </figure>
    </section>
    <div class="foundation-strip wrap">
        <span>${icon('code')}${escapeHtml(t.license)}</span>
        <span>${icon('folder')}${escapeHtml(t.foundationFiles)}</span>
        <span>${icon('lock')}${escapeHtml(t.foundationControl)}</span>
        <a href="${release.html_url}"><span class="release-dot"></span>${escapeHtml(t.release)} <strong>${escapeHtml(release.tag_name.replace('v_', 'v'))}</strong>${icon('external')}</a>
    </div>
    <section class="features-section wrap" id="capabilities" aria-labelledby="features-title">
        <div class="section-heading"><div><p class="eyebrow">${escapeHtml(t.featuresEyebrow)}</p><h2 id="features-title">${escapeHtml(t.intro)}</h2></div><p>${escapeHtml(t.introText)}</p></div>
        <div class="feature-columns">${t.items.map(([symbol,title,text],index) => `<article><span class="feature-symbol">${icon(symbol)}</span><h3>${escapeHtml(title)}</h3><p>${escapeHtml(text)}</p><a class="text-link" href="${index === 1 ? prefix + 'assets/real-lightbox.jpg' : docs}"${index === 1 ? ' data-open-capture="viewer"' : ''}>${escapeHtml(t.featureLinks[index])} ${icon('arrow')}</a></article>`).join('\n')}</div>
    </section>
    <section class="story-section wrap" aria-labelledby="story-title">
        <figure class="story-photograph">${capture(locale,3,prefix)}<figcaption>${escapeHtml(t.captures[3].caption)}</figcaption></figure>
        <div class="story-copy"><p class="eyebrow">${escapeHtml(t.storyEyebrow)}</p><h2 id="story-title">${escapeHtml(t.showcase)}</h2><p>${escapeHtml(t.showcaseText)}</p>
            <div class="story-details">${t.details.map(([symbol,title,text]) => `<details><summary>${icon(symbol)}<span>${escapeHtml(title)}</span>${icon('chevron')}</summary><p>${escapeHtml(text)}</p></details>`).join('\n')}</div>
        </div>
    </section>
    <section class="start-section wrap" aria-labelledby="start-title">
        <div><p class="eyebrow">${escapeHtml(t.startEyebrow)}</p><h2 id="start-title">${escapeHtml(t.start)}</h2><p>${escapeHtml(t.startText)}</p></div>
        <div class="start-actions"><a class="button primary" href="${release.html_url}">${icon('download')}${escapeHtml(t.download)}</a><a class="text-link" href="${docs}">${icon('book')}${escapeHtml(t.docs)}</a></div>
    </section>
    <dialog class="screenshot-dialog" aria-labelledby="capture-title">
        <div class="dialog-top"><h2 id="capture-title">${escapeHtml(t.captures[0].title)}</h2><span>${escapeHtml(t.actualCapture)}</span><button type="button" data-close-capture aria-label="${escapeHtml(t.closeCapture)}">${icon('close')}</button></div>
        <div class="dialog-stage"><button type="button" data-previous-capture aria-label="${escapeHtml(t.previousCapture)}">${icon('chevron')}</button><img class="dialog-image" src="${prefix}assets/real-gallery.jpg" alt="${escapeHtml(t.captures[0].alt)}"><button type="button" data-next-capture aria-label="${escapeHtml(t.nextCapture)}">${icon('chevron')}</button></div>
        <p class="dialog-authenticity">${escapeHtml(t.captureNote)}</p>
        <div class="dialog-footer"><a data-capture-source href="${screenshots[0].source}" target="_blank" rel="noopener noreferrer">${escapeHtml(t.captures[0].sourceLabel)} ↗</a><span>${escapeHtml(t.dialogHint)}</span><span data-capture-counter aria-live="polite">1 / 4</span></div>
    </dialog>`;
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

/**
 * Wrap content with static locale-preserving navigation and optional enhancement.
 * @param {string} locale Supported content locale.
 * @param {string} page Document kind: home or docs.
 * @param {string} body Prepared HTML generated from escaped authored text.
 * @returns {string} Complete HTML document with release-pinned technical links.
 */
function document(locale, page, body) {
    const t = content[locale];
    const prefix = '../'.repeat((locale === 'en' ? 0 : 1) + (page === 'docs' ? 1 : 0)) || './';
    const title = `PHP Gallery — ${page === 'home' ? t.title : t.docs}`;
    const canonical = `${siteBaseUrl}/${route(locale,page)}`;
    const docs = prefix + route(locale,'docs');
    const overview = prefix + route(locale,'home');
    return `<!doctype html>
<html lang="${locale}"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>${escapeHtml(title)}</title>
    <meta name="description" content="${escapeHtml(page === 'home' ? t.lead : t.docsLead)}">
    <meta name="theme-color" content="#f3f5f5"><link rel="canonical" href="${canonical}">
    ${Object.keys(content).map(language => `<link rel="alternate" hreflang="${language}" href="${siteBaseUrl}/${route(language,page)}">`).join('\n')}
    <link rel="alternate" hreflang="x-default" href="${siteBaseUrl}/${route('en',page)}">
    <link rel="stylesheet" href="${prefix}assets/site.css"><script src="${prefix}assets/site.js" defer></script>
    <meta property="og:title" content="${escapeHtml(title)}"><meta property="og:description" content="${escapeHtml(page === 'home' ? t.lead : t.docsLead)}"><meta property="og:url" content="${canonical}"><meta property="og:type" content="website">
</head><body>
    ${iconDefinitions}<a class="skip" href="#main">${escapeHtml(t.skip)}</a><div class="ambient" aria-hidden="true"></div>
    <header class="masthead wrap">
        <a class="brand" href="${overview}"><span class="brand-mark" aria-hidden="true">g.</span>PHP Gallery</a>
        <nav class="main-navigation" aria-label="${escapeHtml(t.home)}">
            <a href="${overview + '#capabilities'}">${escapeHtml(t.features)}</a>
            <a href="${overview + '#showcase'}">${escapeHtml(t.preview)}</a>
            <a href="${docs}"${page === 'docs' ? ' aria-current="page"' : ''}>${escapeHtml(t.docs)}</a>
        </nav>
        <div class="header-tools"><a class="github-link" href="${repository}">GitHub ${icon('external')}</a>
            <button class="theme-toggle icon-button" type="button" hidden data-dark-label="${escapeHtml(t.darkTheme)}" data-light-label="${escapeHtml(t.lightTheme)}" aria-label="${escapeHtml(t.darkTheme)}">${icon('moon')}</button>
            <details class="languages"><summary aria-label="${escapeHtml(t.language)}: ${escapeHtml(t.name)}">${locale.toUpperCase()}</summary><div class="language-list">${Object.keys(content).map(language => `<a lang="${language}" hreflang="${language}" href="${prefix}${route(language,page)}"${language === locale ? ' aria-current="true"' : ''}>${escapeHtml(content[language].name)}</a>`).join('')}</div></details>
        </div>
    </header>
    <main id="main" tabindex="-1"${page === 'docs' ? ' class="docs-main wrap"' : ''}>${body}</main>
    <footer class="footer wrap"><div class="footer-main"><a class="brand" href="${overview}"><span class="brand-mark" aria-hidden="true">g.</span>PHP Gallery</a><p>${escapeHtml(t.footer)}</p><div><a href="${repository}">GitHub</a><a href="${source}/LICENSE">MIT</a><a href="${source}/README.md">${escapeHtml(t.readme)}</a><a href="${docs + '#reference'}">${escapeHtml(t.notes)}</a></div></div>
        <details class="capture-credits"><summary>${escapeHtml(t.captureSources)}</summary><p>${escapeHtml(t.captureProvenance)} <a href="${screenshots[0].source}" target="_blank" rel="noopener noreferrer">${escapeHtml(t.liveGallery)}</a> · <a href="${screenshots[2].source}" target="_blank" rel="noopener noreferrer">${escapeHtml(t.captures[2].sourceLabel)}</a></p></details>
    </footer>
</body></html>\n`;
}

for (const locale of Object.keys(content)) {
    for (const page of ['home','docs']) {
        const directory = resolve(output, route(locale,page));
        mkdirSync(directory, {recursive:true});
        writeFileSync(resolve(directory,'index.html'), document(locale,page,page === 'home' ? home(locale) : documentation(locale)));
    }
}
console.log(`Website built: ${output}; release ${release.tag_name}; release commit ${commit}`);
