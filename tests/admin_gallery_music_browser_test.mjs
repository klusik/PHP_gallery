/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_gallery_music_browser_test.mjs
 * Module Type: Browser Regression Test
 * Purpose: Exercise the local gallery Music editor in its nested Media tabs.
 * Responsibilities: Verify chooser, source groups, canonical writes and responsive layout.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {execFileSync} from 'node:child_process';
import {mkdir, readFile, writeFile} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';

const executable = process.argv[2];
if (!executable || executable.startsWith('--')) {
    if (process.env.PHP_GALLERY_BROWSER_REQUIRED === '1') {
        throw new Error('Required Chromium is unavailable.');
    }
    console.log('SKIP Admin Gallery Music browser: Chromium executable not supplied.');
    process.exit(0);
}

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const phpBinary = process.argv[3] || process.env.PHP_BINARY || 'php';
const fixturePath = path.join(
    root,
    'tests/support/admin_gallery_music_render_fixture.php',
);
/** Bound fixture rendering before Chromium starts and for each mocked search/refresh response.
 * Type: integer. Units: milliseconds. Scope: one PHP view-render subprocess.
 * Consumers: execFileSync. Rationale: deterministic prepared models require one view render and must fail closed instead of hanging the browser child. Value: 10000.
 */
const PHP_RENDER_TIMEOUT_MS = 10000;
/** Bound browser-owned form and search completion observations.
 * Type: integer. Units: milliseconds. Scope: one interaction phase.
 * Consumers: waitForIdle. Rationale: local fixture responses should settle promptly while allowing native form and coordinator promise turns. Value: 5000.
 */
const ACTION_TIMEOUT_MS = 5000;
/** Keep only one bounded browser exception detail for startup diagnostics.
 * Type: integer. Units: UTF-16 code units. Scope: latest page error summary.
 * Consumers: window.__galleryFixtureError and owned fixture timeout expression.
 * Rationale: module parse/import failures must be diagnosable without retaining
 * an unbounded stream of browser events or oversized page messages. Value: 400.
 */
const BROWSER_ERROR_TEXT_LIMIT = 400;
/** Keep bounded state polling from monopolizing the browser task queue.
 * Type: integer. Units: milliseconds. Scope: one browser observation.
 * Consumers: waitForIdle. Rationale: a short timer yield allows fetch completion, paint and event handlers to run. Value: 15.
 */
const POLL_INTERVAL_MS = 15;
/** Keep the isolated browser process inside the browser-map child budget.
 * Type: integer. Units: milliseconds. Scope: one hosted Chromium regression child.
 * Consumers: runHeadlessBrowserFixture. Rationale: one PHP render phase, responsive layout capture and canonical form workflow need startup and teardown headroom. Value: 60000.
 */
const BROWSER_TIMEOUT_MS = 60000;
/** Desired review layout for the initial local-media view.
 * Type: integer. Units: CSS pixels. Scope: desktop fixture viewport.
 * Consumers: runHeadlessBrowserFixture and screenshot assertion. Rationale: matches the companion Admin Music desktop regression width. Value: 1280.
 */
const DESKTOP_VIEWPORT_WIDTH = 1280;
/** Desired narrow layout for the local-media tab regression.
 * Type: integer. Units: CSS pixels. Scope: mobile fixture viewport.
 * Consumers: setViewport and screenshot assertion. Rationale: covers the established narrow phone width. Value: 390.
 */
const MOBILE_VIEWPORT_WIDTH = 390;
/** Fixture page height leaves room for the nested panel and chooser controls.
 * Type: integer. Units: CSS pixels. Scope: both browser viewports.
 * Consumers: runHeadlessBrowserFixture and setViewport. Rationale: one stable height keeps viewport comparison focused on width. Value: 900.
 */
const VIEWPORT_HEIGHT = 900;
/** Match the exact prepared catalog input bound, not a browser-added limit.
 * Type: integer. Units: UTF-8 query bytes. Scope: local Music catalog chooser.
 * Consumers: native maxlength regression assertion. Rationale: protect the server-prepared 120-byte chooser contract. Value: 120 bytes.
 */
const MUSIC_QUERY_LIMIT_BYTES = 120;
/** Account for subpixel layout rounding when comparing the inline PDF editor to its available panel width.
 * Type: number. Units: CSS pixels. Scope: production Admin PDF editor geometry.
 * Consumers: desktop and 390px editor-to-panel assertions.
 * Rationale: fractional borders and device-independent layout can differ by one pixel without consuming or clipping available width. Value: 2.
 */
const PDF_EDITOR_WIDTH_TOLERANCE_PX = 2;
/** Match the compact production Admin action hitbox used by Music and PDF controls.
 * Type: integer. Units: CSS pixels. Scope: visible editor icon controls.
 * Consumers: computed-style assertion in the prepared Admin Media fixture.
 * Rationale: prove controls stay icon-sized instead of inheriting full-width submit styles.
 * Value: 28.
 */
const ADMIN_ICON_HITBOX_PX = 28;
/** Match the presentation editor's slightly larger shared icon controls.
 * Type: integer. Units: CSS pixels. Scope: visible presentation editor icons.
 * Consumers: computed-style assertion for Presentation action controls.
 * Rationale: the production Presentation owner uses 30px action targets beside its compact settings.
 * Value: 30.
 */
const PRESENTATION_ICON_HITBOX_PX = 30;
/** Bound public drawer icon controls to the shared media icon scale.
 * Type: integer. Units: CSS pixels. Scope: public PDF drawer actions.
 * Consumers: computed-style assertion in the isolated public stylesheet context.
 * Rationale: production uses a 1.65em icon button at the fixture's 16px base size.
 * Value: 28.
 */
const PUBLIC_ICON_HITBOX_MAX_PX = 28;
/** Bound the isolated public drawer's vertical viewport.
 * Type: integer. Units: CSS pixels. Scope: public stylesheet iframe fixture.
 * Consumers: the iframe's intrinsic viewport for drawer action styles.
 * Rationale: enough height to expose the rendered document action row.
 * Value: 600.
 */
const PUBLIC_DRAWER_VIEWPORT_HEIGHT = 600;
/** Shrink the public style probe outside visible page content without hiding its layout.
 * Type: number. Units: CSS transform scale. Scope: the off-canvas style-only iframe.
 * Consumers: iframe placement while its child document retains the declared viewport.
 * Rationale: prevent a second page from covering screenshots or adding document overflow.
 * Value: 0.001.
 */
const PUBLIC_DRAWER_PROBE_SCALE = 0.001;
/** One JSON-compatible value returned by the shared DevTools evaluator.
 * @typedef {null|boolean|number|string|Array<FixtureBrowserJsonValue>|Record<string,FixtureBrowserJsonValue>} FixtureBrowserJsonValue
 */

/** Render a production view fixture with one explicit bounded PHP deadline.
 * @param {string} mode Fixture page, card or filtered choice rows.
 * @param {string} [query] Search input for prepared catalog rows.
 * @returns {string} Production PHP view output.
 */
function renderFixture(mode, query = '') {
    return execFileSync(phpBinary, [fixturePath, mode, query], {
        encoding: 'utf8',
        windowsHide: true,
        timeout: PHP_RENDER_TIMEOUT_MS,
    });
}

const initialMarkup = renderFixture('page');
const refreshedMusicCard = renderFixture('music-card');
const filteredChoices = renderFixture('choices', 'Night');
const noPresentationMarkup = renderFixture('without-presentation');
const encodedFilteredChoices = JSON.stringify(filteredChoices)
    .replaceAll('<', '\\u003c');
const encodedRefreshedMusicCard = JSON.stringify(refreshedMusicCard)
    .replaceAll('<', '\\u003c');
const encodedNoPresentationMarkup = JSON.stringify(noPresentationMarkup)
    .replaceAll('<', '\\u003c');
assert.ok(!encodedFilteredChoices.includes('<')
    && JSON.parse(encodedFilteredChoices) === filteredChoices,
'Filtered production markup must be safe inside the host module script and round-trip exactly.');
assert.ok(!encodedRefreshedMusicCard.includes('<')
    && JSON.parse(encodedRefreshedMusicCard) === refreshedMusicCard,
'Refreshed production markup must be safe inside the host module script and round-trip exactly.');
assert.ok(!encodedNoPresentationMarkup.includes('<')
    && JSON.parse(encodedNoPresentationMarkup) === noPresentationMarkup,
'Capability-probe production markup must be safe inside the host module script and round-trip exactly.');
const page = String.raw`<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/public/assets/styles/base.css">
<link rel="stylesheet" href="/public/assets/styles/public.css">
<link rel="stylesheet" href="/public/assets/styles/lightbox.css">
<link rel="stylesheet" href="/public/assets/styles/admin.css">
<link rel="stylesheet" href="/public/assets/styles/admin-layout.css">
<link rel="stylesheet" href="/public/assets/styles/admin-subtabs.css">
<link rel="stylesheet" href="/public/assets/styles/admin-music.css">
<link rel="stylesheet" href="/public/assets/styles/side-panel.css">
<link rel="stylesheet" href="/public/assets/styles/admin-gallery-create.css">
<link rel="stylesheet" href="/public/assets/styles/utilities.css">
<link rel="stylesheet" href="/public/assets/styles.css">
<link rel="stylesheet" href="/public/assets/styles/admin-gallery-api.css">
<link rel="stylesheet" href="/public/assets/styles/admin-gallery-access.css">
<link rel="stylesheet" href="/public/assets/styles/admin-gallery-display.css">
<link rel="stylesheet" href="/public/assets/styles/admin-gallery-media.css">
<link rel="stylesheet" href="/public/assets/styles/breadcrumbs.css">
<style>
body.admin-page { margin: 1rem; }
#results { white-space: pre-wrap; overflow-wrap: anywhere; }
[data-admin-side-panel-body] { width: min(100%, 58rem); min-width: 0; }
#admin-edit-media { min-width: 0; }
</style>
</head><body class="admin-page"><pre id="results"></pre><main id="mount">${initialMarkup}</main>
<script>
window.__galleryFixtureError = '';
window.addEventListener('error', event => {
    window.__galleryFixtureError = String(event.message || 'Unspecified browser error')
        .slice(0, ${BROWSER_ERROR_TEXT_LIMIT});
});
window.addEventListener('unhandledrejection', event => {
    window.__galleryFixtureError = String(event.reason)
        .slice(0, ${BROWSER_ERROR_TEXT_LIMIT});
});
</script>
<script type="module">
import {setupGalleryMusic} from '/public/assets/gallery-modules/admin-gallery-music.js?v=20261020-unified-gallery-media-v3';
import {setupAdminNestedTabs} from '/public/assets/gallery-modules/admin-nested-tabs.js';

const check = (condition, message) => {
    if (!condition) throw new Error(message);
};
/** Capture visible icon geometry and presentation in one production context.
 * @typedef {{label:string,width:number,height:number,svgWidth:number,
 *     svgHeight:number,background:string,borders:string[],boxShadow:string,
 *     visible:boolean}} IconStyleSnapshot
 */
/** Inspect native SVG action controls without relying on screenshot pixels.
 * @param {ParentNode} scope Admin document or isolated public drawer document.
 * @param {string} selector Selector for actual rendered action icons.
 * @returns {IconStyleSnapshot[]} Visible control and glyph measurements.
 */
function iconStyleSnapshots(scope, selector) {
    return Array.from(scope.querySelectorAll(selector))
        .filter(control => control.nodeType === 1
            && control.checkVisibility({
                visibilityProperty: true,
                opacityProperty: true,
            }))
        .map(control => {
            const style = control.ownerDocument.defaultView.getComputedStyle(control);
            const svg = control.querySelector('svg');
            const svgStyle = svg
                ? control.ownerDocument.defaultView.getComputedStyle(svg)
                : null;
            const bounds = control.getBoundingClientRect();
            const glyph = svg?.getBoundingClientRect();
            return {
                label: control.getAttribute('aria-label')
                    || control.getAttribute('title') || control.textContent.trim(),
                width: bounds.width,
                height: bounds.height,
                svgWidth: glyph?.width || 0,
                svgHeight: glyph?.height || 0,
                background: style.backgroundColor,
                borders: [style.borderTopWidth, style.borderRightWidth,
                    style.borderBottomWidth, style.borderLeftWidth],
                boxShadow: style.boxShadow,
                visible: Boolean(svg && style.visibility === 'visible'
                    && Number(style.opacity) > 0
                    && svgStyle?.display !== 'none'
                    && svgStyle?.visibility === 'visible'
                    && Number(svgStyle.opacity) > 0),
            };
        });
}
/** Require compact transparent icon-only controls with visible SVG glyphs.
 * @param {IconStyleSnapshot[]} snapshots Rendered controls from one context.
 * @param {number} maximumSize Maximum permitted CSS-pixel hitbox dimension.
 * @returns {boolean} Whether every visible control satisfies the shared contract.
 */
function iconStylesAreCompact(snapshots, maximumSize) {
    const transparent = color => color === 'transparent'
        || /rgba\([^)]*,\s*0(?:\.0+)?\)$/.test(color);
    return snapshots.length > 0
        && snapshots.every(icon => icon.visible
            && icon.width > 0 && icon.height > 0
            && icon.width <= maximumSize && icon.height <= maximumSize
            && icon.svgWidth > 0 && icon.svgHeight > 0
            && icon.svgWidth <= icon.width && icon.svgHeight <= icon.height
            && transparent(icon.background)
            && icon.borders.every(width => width === '0px')
            && icon.boxShadow === 'none');
}
const ACTION_TIMEOUT_MS = ${ACTION_TIMEOUT_MS};
const POLL_INTERVAL_MS = ${POLL_INTERVAL_MS};
// Browser execution has its own scope; copy the documented host policies.
const ADMIN_ICON_HITBOX_PX = ${ADMIN_ICON_HITBOX_PX};
const PRESENTATION_ICON_HITBOX_PX = ${PRESENTATION_ICON_HITBOX_PX};
const PUBLIC_ICON_HITBOX_MAX_PX = ${PUBLIC_ICON_HITBOX_MAX_PX};
const MUSIC_QUERY_LIMIT_BYTES = ${MUSIC_QUERY_LIMIT_BYTES};
const FILTERED_CHOICES_HTML = ${encodedFilteredChoices};
const REFRESHED_MUSIC_CARD_HTML = ${encodedRefreshedMusicCard};
const NO_PRESENTATION_MARKUP = ${encodedNoPresentationMarkup};
const fixtureWrites = [];
let failPanelRefresh = false;

/** Wait until one owned playlist write releases its local card.
 * @returns {Promise<void>} Resolves once the gallery Music card is idle.
 */
async function waitForIdle() {
    const deadline = Date.now() + ACTION_TIMEOUT_MS;
    while (Date.now() < deadline) {
        if (document.querySelector('[data-gallery-music][data-music-editor="1"]')
            ?.dataset.musicBusy !== '1') return;
        await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL_MS));
    }
    throw new Error('Local gallery Music operation did not settle.');
}

/** Respond with the exact canonical envelope consumed by the shared Admin coordinator.
 * @param {string} action Submitted music action.
 * @returns {Response} Successful response retaining mutation and panel metadata.
 */
function canonicalMutationResponse(action) {
    return new Response(JSON.stringify({
        ok: true,
        message: 'Music saved successfully.',
        mutation: {
            type: 'update', entity: 'gallery', action: 'gallery.music.' + action,
            entity_ids: [42],
        },
        panel: {
            workflow: 'gallery-edit',
            refresh_url: '/index.php?page=admin_edit_gallery&id=42',
            keep_open: true,
        },
        contexts: [],
        fallback: {redirect_url: '/index.php?page=admin_edit_gallery&id=42'},
        panel_html: failPanelRefresh ? '<section>missing owned card</section>'
            : REFRESHED_MUSIC_CARD_HTML,
    }), {headers: {'Content-Type': 'application/json'}});
}

window.fetch = /** Mock same-origin catalog and mutation endpoints while preserving the production request boundary.
 * @param {RequestInfo|URL} input Production form endpoint.
 * @param {RequestInit} options Production method, credentials and headers.
 * @returns {Promise<Response>} Filtered choice fragment or canonical completion envelope.
 */
async (input, options = {}) => {
    const body = options.body instanceof FormData ? options.body : new FormData();
    const action = String(body.get('music_action') || '');
    fixtureWrites.push({
        url: String(input),
        method: String(options.method || 'GET'),
        credentials: String(options.credentials || ''),
        accept: new Headers(options.headers || {}).get('Accept') || '',
        requestedWith: new Headers(options.headers || {}).get('X-Requested-With') || '',
        action,
        query: String(body.get('music_query') || ''),
        revision: String(body.get('music_revision') || ''),
        catalogRevision: String(body.get('catalog_revision') || ''),
        selectionId: String(body.get('selection_id') || ''),
    });
    if (action === 'choices') {
        return new Response(JSON.stringify({
            ok: true,
            choices_html: FILTERED_CHOICES_HTML,
            catalog_revision: 'catalog:raw/0009',
            write_revision: 'write:0007',
        }), {headers: {'Content-Type': 'application/json'}});
    }
    return canonicalMutationResponse(action);
};

try {
    setupAdminNestedTabs(document);
    setupGalleryMusic();
    const manager = document.querySelector(
        '#admin-gallery-media-music [data-gallery-music][data-music-editor="1"]',
    );
    const sidePanel = document.querySelector('[data-admin-side-panel]');
    const nestedTabs = Array.from(document.querySelectorAll(
        '.gallery-editor-media-tabs [role="tab"][data-admin-subtab-target]',
    ));
    const localSources = Array.from(manager?.querySelectorAll(
        'tr[data-music-source]',
    ) || []);
    const publicEmpty = document.querySelector(
        '.gallery-music[data-music-editor="0"]',
    );
    const publicWarnings = Array.from(
        publicEmpty?.querySelectorAll('.gallery-music-warning[role="status"]') || [],
    );
    check(manager instanceof HTMLElement && sidePanel instanceof HTMLElement
        && sidePanel.dataset.open === '1'
        && nestedTabs.length === 4
        && nestedTabs.map(tab => tab.dataset.adminSubtabTarget).join(',')
            === 'admin-gallery-media-music,admin-gallery-media-pdf,admin-gallery-media-presentation,admin-gallery-media-artwork',
    'Production gallery media view must render one scoped Music/PDF/Presentation/Artwork tab group inside the persistent side panel.');
    const mediaPanels = Array.from(document.querySelectorAll(
        '#admin-edit-media [data-admin-subtab-panel]',
    ));
    for (const target of [
        'admin-gallery-media-pdf',
        'admin-gallery-media-presentation',
        'admin-gallery-media-music',
        'admin-gallery-media-artwork',
    ]) {
        const tab = nestedTabs.find(item => item.dataset.adminSubtabTarget === target);
        tab?.click();
        check(tab?.getAttribute('aria-selected') === 'true'
            && mediaPanels.length === 4
            && mediaPanels.every(panel => panel.hidden === (panel.id !== target)),
        'Music/PDF/Presentation navigation must select only its scoped panel: '
            + target);
    }
    const musicPanel = document.getElementById('admin-gallery-media-music');
    const pdfPanel = document.getElementById('admin-gallery-media-pdf');
    const presentationPanel = document.getElementById(
        'admin-gallery-media-presentation',
    );
    const musicTab = nestedTabs.find(tab =>
        tab.dataset.adminSubtabTarget === 'admin-gallery-media-music');
    const pdfTab = nestedTabs.find(tab =>
        tab.dataset.adminSubtabTarget === 'admin-gallery-media-pdf');
    const presentationTab = nestedTabs.find(tab =>
        tab.dataset.adminSubtabTarget === 'admin-gallery-media-presentation');
    musicTab?.click();
    const musicInline = manager.querySelector(':scope > .gallery-media-inline');
    check(musicPanel?.hidden === false
        && musicInline instanceof HTMLElement
        && musicInline.querySelector(':scope > .gallery-media-body')
        ?.contains(manager.querySelector('.gallery-music-table'))
        && musicInline.querySelector(':scope > header [data-gallery-music-controls]')
            instanceof HTMLElement
        && manager.querySelector(':scope > details.gallery-media-widget') === null,
    'Admin Music must show its list and player inline without a second disclosure.');
    const adminMusicIcons = iconStyleSnapshots(
        document,
        '#admin-edit-media .gallery-music .music-icon',
    );
    check(document.querySelector(
        'link[href="/public/assets/styles/admin-gallery-media.css"]',
    )?.sheet instanceof CSSStyleSheet
        && iconStylesAreCompact(adminMusicIcons, ADMIN_ICON_HITBOX_PX),
        'Admin Music action icons must remain visible compact SVG controls without filled button blocks: '
            + JSON.stringify(adminMusicIcons));
    pdfTab?.click();
    const pdfEditor = pdfPanel?.querySelector(
        ':scope > .gallery-pdf-inline-editor',
    );
    const pdfInline = pdfEditor?.querySelector(
        ':scope > .gallery-media-inline',
    );
    const ofpDisclosure = pdfPanel?.querySelector(
        'details.admin-simbrief-legacy-disclosure',
    );
    // Chromium can retain child layout rectangles inside closed native details.
    // Check actual visibility rather than inferring paint from rectangle count.
    check(pdfPanel?.hidden === false
        && pdfEditor instanceof HTMLElement
        && pdfInline instanceof HTMLElement
        && pdfInline.querySelector('.gallery-pdf-table') instanceof HTMLTableElement
        && pdfInline.querySelector('[data-pdf-attachment-form]')
            instanceof HTMLFormElement
        && pdfEditor.querySelector(':scope > details.gallery-media-widget') === null
        && ofpDisclosure instanceof HTMLDetailsElement
        && ofpDisclosure.open === false
        && ofpDisclosure.querySelector('[data-simbrief-legacy-panel]')
            ?.checkVisibility() === false,
    'Admin PDF controls must be inline in the PDF subtab while the OFP workflow stays in a closed disclosure.');
    const adminPdfIcons = iconStyleSnapshots(
        document,
        '#admin-edit-media .gallery-pdf-inline-editor .gallery-pdf-icon',
    );
    check(iconStylesAreCompact(adminPdfIcons, ADMIN_ICON_HITBOX_PX),
        'Admin PDF action icons must remain visible compact SVG controls without filled button blocks: '
            + JSON.stringify(adminPdfIcons));
    presentationTab?.click();
    const presentation = presentationPanel?.querySelector(
        '[data-gallery-presentation-editor]',
    );
    // Count the five saved gallery preferences separately from sharing options.
    const presentationSettings = Array.from(
        presentation?.querySelectorAll(
            ':scope > .gallery-presentation-setting',
        ) || [],
    );
    const presentationHelp = presentation?.querySelector(
        'details.gallery-presentation-help',
    );
    const presentationShare = presentation?.querySelector(
        '.gallery-presentation-actions > details[data-presentation-share-widget]',
    );
    check(presentationPanel?.hidden === false
        && presentation instanceof HTMLElement
        && presentationSettings.length === 5
        && presentationHelp instanceof HTMLDetailsElement
        && presentationHelp.open === false
        && presentation.querySelector(':scope > .gallery-media-widget') === null
        && presentationShare instanceof HTMLDetailsElement
        && presentationShare.open === false,
    'Presentation controls must remain compact direct settings with a closed contextual help disclosure.');
    const presentationIcons = iconStyleSnapshots(
        document,
        '#admin-edit-media .gallery-presentation-settings .music-icon',
    );
    check(iconStylesAreCompact(
        presentationIcons,
        PRESENTATION_ICON_HITBOX_PX,
    ), 'Presentation editor actions must retain compact visible SVG controls: '
        + JSON.stringify(presentationIcons));
    musicTab?.click();
    check(document.querySelector(
        '#admin-gallery-media-pdf [data-gallery-pdf-documents]',
    ) instanceof HTMLElement
        && document.querySelector(
            '#admin-gallery-media-presentation [data-gallery-presentation-editor]',
        ) instanceof HTMLElement,
    'The PDF and Presentation tabs must retain their production-rendered editor content.');
    check(localSources.length === 3
        && localSources[0].dataset.musicSource === 'album:801'
        && localSources[0].textContent.includes('Live album')
        && localSources[1].dataset.musicSource === 'song:901'
        && localSources[0].matches('tr.gallery-music-source')
        && !localSources[1].matches('tr.gallery-music-source')
        && localSources[1].matches('tr[data-music-track]')
        && localSources[1].querySelectorAll('form[data-gallery-music-form]').length >= 4
        && localSources[1].textContent.includes('Quiet Morning')
        && localSources[2].dataset.musicSource === 'album:802'
        && localSources[2].textContent.includes('Empty Weekend Set')
        && localSources[2].querySelector(
            'button[name="music_action"][value="delete"]',
        ) instanceof HTMLButtonElement
        && localSources[2].querySelector('[data-music-track]') === null
        && document.querySelectorAll(
            '#admin-gallery-media-music tr.gallery-music-source',
        ).length === 2,
    'Live albums must remain source group headers while each direct song stays one track row with its own controls.');
    const firstAlbumMembers = [];
    for (let row = localSources[0].nextElementSibling;
        row && row !== localSources[1];
        row = row.nextElementSibling) {
        if (row.matches('tr[data-music-track]')) firstAlbumMembers.push(row);
    }
    check(firstAlbumMembers.length === 2
        && firstAlbumMembers.every(row =>
            row.dataset.musicTrack.startsWith('42:album-track-'))
        && localSources[0].querySelector(
            'button[name="music_action"][value="delete"]',
        ) instanceof HTMLButtonElement,
    'A live-album source must render its two member tracks under one removable group header.');
    check(localSources.every(source => {
        const remove = source.querySelector('button[name="music_action"][value="delete"]');
        const download = source.querySelector('button[name="music_action"][value="download"]');
        return remove instanceof HTMLButtonElement
            && download instanceof HTMLButtonElement
            && download.getAttribute('aria-pressed') === 'true'
            && source.querySelector('input[name="music_download_enabled"]')?.value === '0';
    }), 'Each live/direct source must retain its remove action and explicit download permission control.');
    check(publicEmpty instanceof HTMLElement
        && publicWarnings.some(warning => warning.textContent.includes('currently unavailable'))
        && publicEmpty.querySelector(':scope > details.gallery-media-widget')
            instanceof HTMLDetailsElement
        && publicEmpty.querySelector(':scope > details.gallery-media-widget')
            .open === false
        && publicEmpty.querySelectorAll('form, [data-music-action], audio').length === 0
        && publicEmpty.querySelectorAll('tbody tr[data-music-track]').length === 0,
    'A public empty playlist may show availability warnings, but must not expose editor or playback controls.');
    const publicPdf = document.querySelector(
        '.gallery-pdf-documents:not(.gallery-pdf-inline-editor)',
    );
    const publicPdfDisclosure = publicPdf?.querySelector(
        ':scope > details.gallery-media-widget',
    );
    check(publicPdf instanceof HTMLElement
        && publicPdfDisclosure instanceof HTMLDetailsElement
        && publicPdfDisclosure.open === false
        && publicPdf.querySelector('a[href="/gallery/travel-notes.pdf"]')
            instanceof HTMLAnchorElement,
    'Public PDF content must remain available through its native closed disclosure.');
    const drawerFrame = document.createElement('iframe');
    drawerFrame.title = 'Isolated public gallery media drawer style context';
    drawerFrame.style.cssText = 'position:fixed;left:0;top:0;width:100vw;height:'
        + ${PUBLIC_DRAWER_VIEWPORT_HEIGHT} + 'px;border:0;transform:scale('
        + ${PUBLIC_DRAWER_PROBE_SCALE}
        + ');transform-origin:top left;pointer-events:none;opacity:0';
    drawerFrame.srcdoc = '<!doctype html><html lang="en"><head>'
        + '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        + '<link rel="stylesheet" href="/public/assets/styles/base.css">'
        + '<link rel="stylesheet" href="/public/assets/styles/public.css">'
        + '<link rel="stylesheet" href="/public/assets/styles/lightbox.css">'
        + '<link rel="stylesheet" href="/public/assets/styles/public-shared.css">'
        + '<link rel="stylesheet" href="/public/assets/styles/public-content-widgets.css">'
        + '<link rel="stylesheet" href="/public/assets/styles/utilities.css">'
        + '<link rel="stylesheet" href="/public/assets/styles.css">'
        + '<link rel="stylesheet" href="/public/assets/styles/breadcrumbs.css">'
        + '</head><body><div class="gallery-attachment-applets"></div></body></html>';
    const drawerReady = new Promise((resolve, reject) => {
        drawerFrame.addEventListener('load', resolve, {once: true});
        drawerFrame.addEventListener('error', reject, {once: true});
    });
    document.body.append(drawerFrame);
    await drawerReady;
    const drawerDocument = drawerFrame.contentDocument;
    drawerDocument.querySelector('.gallery-attachment-applets')
        .append(publicPdf.cloneNode(true));
    const drawerPdf = drawerDocument.querySelector('.gallery-pdf-documents');
    const drawerDisclosure = drawerPdf?.querySelector(
        ':scope > details.gallery-media-widget',
    );
    drawerDisclosure.open = true;
    const drawerIcons = iconStyleSnapshots(
        drawerDocument,
        '.gallery-pdf-documents .gallery-pdf-icon',
    );
    const drawerStylesheets = Array.from(
        drawerDocument.querySelectorAll('link[rel="stylesheet"]'),
    );
    check(drawerPdf?.nodeType === 1
        && !drawerPdf.closest('#admin-edit-media')
        && drawerDisclosure?.tagName === 'DETAILS'
        && drawerDisclosure.open
        && drawerStylesheets.length === 8
        && drawerStylesheets.every(link => link.sheet)
        && iconStylesAreCompact(drawerIcons, PUBLIC_ICON_HITBOX_MAX_PX),
    'Public drawer actions must retain native disclosure behavior and compact visible SVG controls under the public stylesheet stack: '
        + JSON.stringify(drawerIcons));

    const settingsForm = document.getElementById('admin-gallery-settings-42');
    const artworkPanel = document.getElementById('admin-gallery-media-artwork');
    const artworkControls = Array.from(
        artworkPanel?.querySelectorAll('[form]') || [],
    );
    const brandingUpload = artworkPanel?.querySelector(
        'input[name="branding_logo_upload"]',
    );
    check(settingsForm instanceof HTMLFormElement
        && artworkPanel instanceof HTMLElement
        && artworkControls.length > 0
        && artworkControls.every(control => control.form === settingsForm)
        && brandingUpload instanceof HTMLInputElement
        && brandingUpload.form === settingsForm,
    'Artwork and branding controls must use native association with the shared gallery settings form.');

    const capabilityProbe = document.createElement('div');
    capabilityProbe.innerHTML = NO_PRESENTATION_MARKUP;
    const capabilityTabs = Array.from(capabilityProbe.querySelectorAll(
        '.gallery-editor-media-tabs [role="tab"]',
    ));
    check(capabilityTabs.length === 3
        && capabilityTabs.every(tab =>
            tab.dataset.adminSubtabTarget !== 'admin-gallery-media-presentation')
        && capabilityProbe.querySelector(
            '#admin-gallery-media-presentation',
        ) === null,
    'A disabled Presentation capability must omit both its tab and its panel rather than leave an empty section.');

    const initialRevisionFields = Array.from(manager.querySelectorAll(
        'form[data-gallery-music-form] input[name="music_revision"]',
    ));
    const initialRevisions = initialRevisionFields.map(input => input.value);
    check(initialRevisions.length >= 3
        && initialRevisions.every(revision => revision === 'write:0007'),
    'Initial source and preference forms must preserve the exact prepared write CAS token.');
    // Filtering replaces choice forms, while mounted playlist fields must keep
    // their node identities and exact values independently of result count.
    const mountedRevisionFields = initialRevisionFields
        .filter(input => !input.closest('[data-music-catalog-results]'))
        .map(input => ({input, value: input.value}));
    const picker = manager.querySelector('details.gallery-music-picker');
    const search = picker?.querySelector('form[data-gallery-music-search]');
    const query = search?.querySelector('input[name="music_query"]');
    check(picker instanceof HTMLDetailsElement
        && search instanceof HTMLFormElement
        && query instanceof HTMLInputElement
        && query.maxLength === MUSIC_QUERY_LIMIT_BYTES,
    'The catalog chooser must expose a bounded native query form.');
    picker.open = true;
    query.value = 'Night';
    search.requestSubmit(search.querySelector('button[type="submit"]'));
    await waitForIdle();
    const currentManager = document.querySelector(
        '#admin-gallery-media-music [data-gallery-music][data-music-editor="1"]',
    );
    const searchWrite = fixtureWrites.at(-1);
    check(currentManager === manager
        && searchWrite?.method === 'POST'
        && searchWrite.url === '/index.php?page=admin_edit_gallery&id=42'
        && searchWrite.credentials === 'same-origin'
        && searchWrite.accept === 'application/json'
        && searchWrite.requestedWith === 'XMLHttpRequest'
        && searchWrite.action === 'choices'
        && searchWrite.query === 'Night',
    'Bounded catalog search must preserve the mounted card and its POST, credentials and JSON request contract.');
    check(manager.querySelectorAll('[data-music-catalog-results] form').length === 1
        && manager.querySelector('[data-music-catalog-results]')?.textContent
            .includes('Night Drive Theme'),
    'The Night query must replace only the choices with its one matching song.');
    const resultRevisionFields = Array.from(manager.querySelectorAll(
        '[data-music-catalog-results] input[name="music_revision"]',
    ));
    check(mountedRevisionFields.length > 0
        && mountedRevisionFields.every(({input, value}) => input.isConnected
            && manager.contains(input) && input.value === value)
        && resultRevisionFields.length === 1
        && resultRevisionFields.every(input => input.value === initialRevisions[0]),
    'Search must retain mounted playlist revision fields and their raw CAS values, and give replacement choices the same raw token.');

    const addSongForm = manager.querySelector(
        '[data-music-catalog-results] form[data-gallery-music-form]',
    );
    const pageUrlBeforeWrite = location.href;
    const panelBeforeWrite = document.querySelector('[data-admin-side-panel]');
    const writesBeforeAdd = fixtureWrites.length;
    addSongForm.requestSubmit(addSongForm.querySelector('button[type="submit"]'));
    await waitForIdle();
    const refreshedManager = document.querySelector(
        '#admin-gallery-media-music [data-gallery-music][data-music-editor="1"]',
    );
    const addWrite = fixtureWrites.at(-1);
    check(fixtureWrites.length === writesBeforeAdd + 1
        && addWrite?.method === 'POST'
        && addWrite.url === '/index.php?page=admin_edit_gallery&id=42'
        && addWrite.credentials === 'same-origin'
        && addWrite.accept === 'application/json'
        && addWrite.requestedWith === 'XMLHttpRequest'
        && addWrite?.action === 'add_song'
        && addWrite.selectionId === '902'
        && addWrite.revision === 'write:0007'
        && addWrite.catalogRevision === 'catalog:raw/0009'
        && refreshedManager !== manager
        && refreshedManager?.querySelector('[data-music-status]')?.textContent
            === 'Music saved successfully.'
        && panelBeforeWrite === document.querySelector('[data-admin-side-panel]')
        && panelBeforeWrite?.dataset.open === '1'
        && location.href === pageUrlBeforeWrite,
    'A canonical source assignment must refresh only its local Music card, preserve the side panel and stay on the same URL.');

    failPanelRefresh = true;
    const staleManager = refreshedManager;
    const staleSource = staleManager.querySelector(
        'tr[data-music-source="song:901"]',
    );
    const staleSubmit = staleSource.querySelector(
        'button[name="music_action"][value="delete"]',
    );
    const staleForm = staleSubmit.closest('form[data-gallery-music-form]');
    const writesBeforeStale = fixtureWrites.length;
    staleForm.requestSubmit(staleSubmit);
    await waitForIdle();
    const writesAfterStale = fixtureWrites.length;
    const staleMessage = staleManager.querySelector('[data-music-status]')?.textContent || '';
    staleForm.requestSubmit(staleSubmit);
    await waitForIdle();
    check(writesAfterStale === writesBeforeStale + 1
        && fixtureWrites.length === writesAfterStale
        && staleManager.dataset.musicRefreshBlocked === '1'
        && staleMessage.includes('could not be refreshed')
        && location.href === pageUrlBeforeWrite,
    'A successful write with an unrefreshed panel must warn and block another local write without navigating.');
    document.getElementById('results').textContent =
        'BROWSER PASS local gallery Music tabs, bounded chooser, source groups, CAS, coordinator and stale lock';
} catch (error) {
    document.getElementById('results').textContent =
        'BROWSER FAIL ' + (error.stack || String(error));
}
</script></body></html>`;

const server = createServer(/** Serve one production-render fixture page and repository assets on loopback.
 * @param {import('node:http').IncomingMessage} request Isolated browser request.
 * @param {import('node:http').ServerResponse} response Owned response writer.
 * @returns {Promise<void>} Completes one local file or page response.
 */ async (request, response) => {
    try {
        const url = new URL(request.url || '/', 'http://127.0.0.1');
        if (url.pathname === '/') {
            response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
            response.end(page);
            return;
        }
        const file = path.resolve(root, '.' + url.pathname);
        if (!url.pathname.startsWith('/public/assets/')
            || !file.startsWith(root + path.sep)) {
            response.writeHead(404).end();
            return;
        }
        response.writeHead(200, {
            'Content-Type': file.endsWith('.css') ? 'text/css' : 'text/javascript',
        });
        response.end(await readFile(file));
    } catch {
        response.writeHead(404).end();
    }
});

await new Promise(/** Bind the owned fixture server to loopback only.
 * @param {function():void} resolve Listen completion receiver.
 * @returns {void} Resolves after a private ephemeral port is ready.
 */ resolve => server.listen(0, '127.0.0.1', resolve));
try {
    const url = 'http://127.0.0.1:' + server.address().port + '/';
    const evidenceDirectory = path.join(
        root,
        'cache',
        'test-audit',
        'admin-gallery-music-browser-' + process.pid,
    );
    await mkdir(evidenceDirectory, {recursive: true});
    const result = await runHeadlessBrowserFixture(
        executable,
        url,
        'admin-gallery-music-browser-',
        {
            viewport: {
                width: DESKTOP_VIEWPORT_WIDTH,
                height: VIEWPORT_HEIGHT,
            },
            timeoutMs: BROWSER_TIMEOUT_MS,
            timeoutExpression: 'JSON.stringify({result:document.getElementById("results")?.textContent||"",error:window.__galleryFixtureError||""})',
            interact: /** Capture desktop and mobile layouts while the fixture script owns browser behavior.
             * @param {{evaluate:function(string):Promise<FixtureBrowserJsonValue|undefined>,captureScreenshot:function():Promise<string>,setViewport:function({width:number,height:number}):Promise<void>,mouse:function('mouseMoved'|'mousePressed'|'mouseReleased',number,number,number,number=):Promise<void>}} tools Owned browser controls.
             * @returns {Promise<void>} Saves review screenshots and releases the bounded interaction sequence.
             */ async ({evaluate, captureScreenshot, setViewport, mouse}) => {
                const readinessDeadline = Date.now() + ACTION_TIMEOUT_MS;
                let readinessResultText = '';
                while (Date.now() < readinessDeadline) {
                    readinessResultText = String(await evaluate(
                        'document.getElementById("results")?.textContent || ""',
                    ) || '');
                    assert.ok(!readinessResultText.startsWith('BROWSER FAIL'),
                        readinessResultText);
                    if (readinessResultText.startsWith('BROWSER PASS')) break;
                    await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL_MS));
                }
                const readinessDiagnostics = await evaluate(
                    'JSON.stringify({result:document.getElementById("results")?.textContent||"",error:window.__galleryFixtureError||""})',
                );
                assert.ok(readinessResultText.startsWith('BROWSER PASS'),
                    'Gallery media module did not report readiness: '
                        + String(readinessDiagnostics || 'no page diagnostics'));
                const desktopTargets = await evaluate(`Array.from(document.querySelectorAll(
                        '.gallery-editor-media-tabs [data-admin-subtab-target]',
                    ), tab => tab.getAttribute('data-admin-subtab-target'))`);
                const desktopPanelStates = [];
                const desktopSelectionDeadline = Date.now() + ACTION_TIMEOUT_MS;
                for (const target of desktopTargets) {
                    const bounds = await evaluate(`(() => {
                        const target = ${JSON.stringify(target)};
                        const tab = document.querySelector(
                            '.gallery-editor-media-tabs [data-admin-subtab-target="' + target + '"]',
                        );
                        tab?.scrollIntoView({block: 'nearest', inline: 'center'});
                        const rect = tab?.getBoundingClientRect();
                        return rect ? {x: rect.x, y: rect.y, width: rect.width, height: rect.height} : null;
                    })()`);
                    assert.ok(bounds?.width > 0 && bounds.height > 0,
                        'Desktop Media tab has no native click target: '
                            + JSON.stringify({target, bounds}));
                    const x = bounds.x + bounds.width / 2;
                    const y = bounds.y + bounds.height / 2;
                    await mouse('mouseMoved', x, y, 0);
                    await mouse('mousePressed', x, y, 1, 1);
                    await mouse('mouseReleased', x, y, 0, 1);
                    let isSelected = await evaluate(`(() => {
                        const target = ${JSON.stringify(target)};
                        const tab = document.querySelector(
                            '.gallery-editor-media-tabs [data-admin-subtab-target="' + target + '"]',
                        );
                        return tab?.getAttribute('aria-selected') === 'true'
                            && document.getElementById(target)?.hidden === false;
                    })()`);
                    while (!isSelected
                        && Date.now() < desktopSelectionDeadline) {
                        await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL_MS));
                        isSelected = await evaluate(`(() => {
                            const target = ${JSON.stringify(target)};
                            const tab = document.querySelector(
                                '.gallery-editor-media-tabs [data-admin-subtab-target="' + target + '"]',
                            );
                            return tab?.getAttribute('aria-selected') === 'true'
                                && document.getElementById(target)?.hidden === false;
                        })()`);
                    }
                    desktopPanelStates.push(await evaluate(`(() => {
                        const target = ${JSON.stringify(target)};
                        const tab = document.querySelector(
                            '.gallery-editor-media-tabs [data-admin-subtab-target="' + target + '"]',
                        );
                        const panel = document.getElementById(target);
                        const body = document.querySelector('[data-admin-side-panel-body]');
                        return {
                            target,
                            tabSelected: tab?.getAttribute('aria-selected') === 'true',
                            visible: panel?.hidden === false,
                            documentWidth: document.documentElement.scrollWidth,
                            viewportWidth: innerWidth,
                            panelWidth: panel?.scrollWidth,
                            panelClientWidth: panel?.clientWidth,
                            pdfEditorWidth: target === 'admin-gallery-media-pdf'
                                ? panel?.querySelector('.gallery-pdf-inline-editor')?.getBoundingClientRect().width ?? null
                                : null,
                            pdfPanelAvailableWidth: target === 'admin-gallery-media-pdf'
                                && panel instanceof HTMLElement
                                ? panel.clientWidth
                                    - parseFloat(getComputedStyle(panel).paddingLeft)
                                    - parseFloat(getComputedStyle(panel).paddingRight)
                                : null,
                            sidePanelWidth: body?.scrollWidth,
                        };
                    })()`));
                }
                assert.ok(Array.isArray(desktopPanelStates)
                    && desktopPanelStates.length === 4
                    && desktopPanelStates.every(panel => panel.tabSelected
                        && panel.visible
                        && panel.documentWidth <= panel.viewportWidth
                        && panel.panelWidth <= panel.panelClientWidth
                        && panel.sidePanelWidth <= panel.viewportWidth
                        && (panel.target !== 'admin-gallery-media-pdf'
                            || panel.pdfEditorWidth !== null
                                && panel.pdfEditorWidth + PDF_EDITOR_WIDTH_TOLERANCE_PX
                                    >= panel.pdfPanelAvailableWidth)),
                'Native desktop subsection selection must show each enabled Media panel without horizontal overflow, and PDF must use its available width: '
                    + JSON.stringify(desktopPanelStates));
                const desktop = Buffer.from(await captureScreenshot(), 'base64');
                assert.equal(desktop.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
                assert.equal(desktop.readUInt32BE(16), DESKTOP_VIEWPORT_WIDTH);
                await writeFile(path.join(evidenceDirectory, 'desktop.png'), desktop);
                await setViewport({
                    width: MOBILE_VIEWPORT_WIDTH,
                    height: VIEWPORT_HEIGHT,
                });
                const mobileTargets = await evaluate(`Array.from(document.querySelectorAll(
                        '.gallery-editor-media-tabs [data-admin-subtab-target]',
                    ), tab => tab.getAttribute('data-admin-subtab-target'))`);
                const mobilePanelStates = [];
                const mobileSelectionDeadline = Date.now() + ACTION_TIMEOUT_MS;
                for (const target of mobileTargets) {
                    const bounds = await evaluate(`(() => {
                        const target = ${JSON.stringify(target)};
                        const tab = document.querySelector(
                            '.gallery-editor-media-tabs [data-admin-subtab-target="' + target + '"]',
                        );
                        tab?.scrollIntoView({block: 'nearest', inline: 'center'});
                        const rect = tab?.getBoundingClientRect();
                        return rect ? {x: rect.x, y: rect.y, width: rect.width, height: rect.height} : null;
                    })()`);
                    assert.ok(bounds?.width > 0 && bounds.height > 0,
                        '390px Media tab has no native click target: '
                            + JSON.stringify({target, bounds}));
                    const x = bounds.x + bounds.width / 2;
                    const y = bounds.y + bounds.height / 2;
                    await mouse('mouseMoved', x, y, 0);
                    await mouse('mousePressed', x, y, 1, 1);
                    await mouse('mouseReleased', x, y, 0, 1);
                    let isSelected = await evaluate(`(() => {
                        const target = ${JSON.stringify(target)};
                        const tab = document.querySelector(
                            '.gallery-editor-media-tabs [data-admin-subtab-target="' + target + '"]',
                        );
                        return tab?.getAttribute('aria-selected') === 'true'
                            && document.getElementById(target)?.hidden === false;
                    })()`);
                    while (!isSelected
                        && Date.now() < mobileSelectionDeadline) {
                        await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL_MS));
                        isSelected = await evaluate(`(() => {
                            const target = ${JSON.stringify(target)};
                            const tab = document.querySelector(
                                '.gallery-editor-media-tabs [data-admin-subtab-target="' + target + '"]',
                            );
                            return tab?.getAttribute('aria-selected') === 'true'
                                && document.getElementById(target)?.hidden === false;
                        })()`);
                    }
                    mobilePanelStates.push(await evaluate(`(() => {
                        const target = ${JSON.stringify(target)};
                        const tab = document.querySelector(
                            '.gallery-editor-media-tabs [data-admin-subtab-target="' + target + '"]',
                        );
                        const panel = document.getElementById(target);
                        const body = document.querySelector('[data-admin-side-panel-body]');
                        return {
                            target,
                            tabSelected: tab?.getAttribute('aria-selected') === 'true',
                            visible: panel?.hidden === false,
                            viewportWidth: innerWidth,
                            documentWidth: document.documentElement.scrollWidth,
                            panelWidth: panel?.scrollWidth,
                            panelClientWidth: panel?.clientWidth,
                            pdfEditorWidth: target === 'admin-gallery-media-pdf'
                                ? panel?.querySelector('.gallery-pdf-inline-editor')?.getBoundingClientRect().width ?? null
                                : null,
                            pdfPanelAvailableWidth: target === 'admin-gallery-media-pdf'
                                && panel instanceof HTMLElement
                                ? panel.clientWidth
                                    - parseFloat(getComputedStyle(panel).paddingLeft)
                                    - parseFloat(getComputedStyle(panel).paddingRight)
                                : null,
                            sidePanelWidth: body?.scrollWidth,
                        };
                    })()`));
                }
                assert.ok(Array.isArray(mobilePanelStates)
                    && mobilePanelStates.length === 4
                    && mobilePanelStates.every(panel => panel.tabSelected
                        && panel.visible
                        && panel.viewportWidth === MOBILE_VIEWPORT_WIDTH
                        && panel.documentWidth <= panel.viewportWidth
                        && panel.panelWidth <= panel.panelClientWidth
                        && panel.sidePanelWidth <= panel.viewportWidth
                        && (panel.target !== 'admin-gallery-media-pdf'
                            || panel.pdfEditorWidth !== null
                                && panel.pdfEditorWidth + PDF_EDITOR_WIDTH_TOLERANCE_PX
                                    >= panel.pdfPanelAvailableWidth)),
                'Native 390px subsection selection must show each enabled Media panel without overflow, and PDF must use its available width: '
                    + JSON.stringify(mobilePanelStates));
                const mobile = Buffer.from(await captureScreenshot(), 'base64');
                assert.equal(mobile.readUInt32BE(16), MOBILE_VIEWPORT_WIDTH);
                await writeFile(path.join(evidenceDirectory, 'mobile.png'), mobile);
            },
        },
    );
    console.log(result.result);
    assert.equal(result.exitCode, 0);
    assert.ok(result.result.startsWith('BROWSER PASS'),
        'Admin Gallery Music browser fixture must complete successfully.');
} finally {
    server.close();
}
