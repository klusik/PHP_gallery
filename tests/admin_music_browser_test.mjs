/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_music_browser_test.mjs
 * Module Type: Browser Regression Test
 * Purpose: Exercise the compact global Music Manager in isolated Chromium.
 * Responsibilities: Verify production rendering, responsive controls, and mutation lifecycle.
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
    console.log('SKIP Admin Music Manager browser: Chromium executable not supplied.');
    process.exit(0);
}

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const phpBinary = process.argv[3] || process.env.PHP_BINARY || 'php';
const fixturePath = path.join(root, 'tests/support/admin_music_render_fixture.php');
/** Browser polling deadline for one in-page action. Type: integer. Units: milliseconds. Scope: this isolated browser fixture. Consumers: waitForIdle(). Rationale: three seconds permit deterministic mocked fetch and native DOM tasks while bounding a stuck mutation; Value: 3000. */
const ACTION_TIMEOUT_MS = 3000;
/** Passive result sampling cadence. Type: integer. Units: milliseconds. Scope: one in-page action. Consumers: waitForIdle(). Rationale: a short task yield observes native fetch and DOM updates without busy polling; Value: 10. */
const ACTION_POLL_MS = 10;
/** Maximum observed delay for a browser search/read cycle, including its bounded PHP render. Type: integer. Units: milliseconds. Scope: one search assertion. Consumers: waitForBrowserState(). Rationale: exceeds the fixture subprocess cap while remaining inside the browser child budget; Value: 12000. */
const SEARCH_RESULT_TIMEOUT_MS = 12000;
/** Result polling deadline. Type: integer. Units: milliseconds. Scope: fixture work after startup and visual capture. Consumers: runHeadlessBrowserFixture(). Rationale: allow bounded search and PHP-render phases while leaving startup and teardown headroom under the registered 90-second child timeout; Value: 60000. */
const BROWSER_TIMEOUT_MS = 60000;
/** Existing shared Admin Theme default used to verify disclosure motion inheritance. Type: string. Units: CSS milliseconds. Scope: this isolated Music Manager browser fixture. Consumers: variants and usage popup animation assertions. Rationale: mirrors theme_admin_side_panel_motion_ms default 260 ms; it is not a new motion policy. Value: 260ms. */
const THEME_SIDE_PANEL_MOTION_DEFAULT = '260ms';
/** PHP production-view render deadline. Type: integer. Units: milliseconds. Scope: one fixture subprocess. Consumers: execFileSync(). Rationale: prepared data and one view render should finish quickly; bound a broken bootstrap before browser startup; Value: 10000. */
const PHP_RENDER_TIMEOUT_MS = 10000;
/** Visual screenshot readiness deadline. Type: integer. Units: milliseconds. Scope: initial rendered document. Consumers: screenshot gate. Rationale: allows CSS and first presentation to settle while staying below the hosted child deadline; Value: 5000. */
const VISUAL_READY_TIMEOUT_MS = 5000;
/** Match the production title popup's click separation for geometry assertions. Type: integer. Units: CSS pixels. Scope: pointer editor placement regression. Consumers: title editor bounds assertions. Rationale: the editor must start beyond the click target; Value: 8. */
const TITLE_EDITOR_GUTTER_PX = 8;
/** Match the production title popup's visible viewport edge for geometry assertions. Type: integer. Units: CSS pixels. Scope: pointer editor placement regression. Consumers: title editor bounds assertions. Rationale: keep a visible edge on either side of the popup; Value: 8. */
const TITLE_EDITOR_VIEWPORT_MARGIN_PX = 8;

/** Render production markup once with the isolated prepared-model fixture. */
const markup = execFileSync(
    phpBinary,
    [fixturePath],
    {encoding: 'utf8', windowsHide: true, timeout: PHP_RENDER_TIMEOUT_MS},
);
const page = String.raw`<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/public/assets/styles/base.css">
<link rel="stylesheet" href="/public/assets/styles/public.css">
<link rel="stylesheet" href="/public/assets/styles/admin.css">
<link rel="stylesheet" href="/public/assets/styles/admin-layout.css">
<link rel="stylesheet" href="/public/assets/styles/admin-dashboard.css">
<link rel="stylesheet" href="/public/assets/styles/admin-music.css">
<style>
/* Model theme values normally prepared by the installed gallery settings. */
body.admin-page {
    --font-family: Inter, ui-sans-serif, system-ui, -apple-system,
        BlinkMacSystemFont, "Segoe UI", sans-serif;
    --ink: #1f2937;
    --muted: #64748b;
    --paper: #f8fafc;
    --panel: #fff;
    --gallery-panel: var(--panel);
    --field: #fff;
    --line: #e2e8f0;
    --accent: #2563eb;
    --accent-dark: #1d4ed8;
    --radius: 10px;
    --admin-accent: var(--accent);
    --admin-border: var(--line);
    --admin-surface: var(--panel);
    --admin-focus: var(--accent);
    margin: 1rem;
    color: var(--ink);
    background: var(--paper);
    font-family: var(--font-family);
    font-size: 16px;
}
.admin-music { max-width: none; }
</style>
</head><body class="admin-page"><pre id="results"></pre><main id="mount">${markup}</main>
<script type="module">
import {setupAdminMusic} from '/public/assets/gallery-modules/admin-music.js?v=20261020-music-tabs-popover-drag-v6';
import {normalizeAdminMutationEnvelope} from '/public/assets/gallery-modules/admin-mutation-completion.js?v=20261016-three-state-covers-v1';
const check = (condition, message) => { if (!condition) throw new Error(message); };
const ACTION_TIMEOUT_MS = ${ACTION_TIMEOUT_MS};
const ACTION_POLL_MS = ${ACTION_POLL_MS};
const SEARCH_RESULT_TIMEOUT_MS = ${SEARCH_RESULT_TIMEOUT_MS};
const THEME_SIDE_PANEL_MOTION_DEFAULT = '${THEME_SIDE_PANEL_MOTION_DEFAULT}';
const TITLE_EDITOR_GUTTER_PX = ${TITLE_EDITOR_GUTTER_PX};
const TITLE_EDITOR_VIEWPORT_MARGIN_PX = ${TITLE_EDITOR_VIEWPORT_MARGIN_PX};
/** One synthetic pointer token shared by each fixture down/move/up sequence. Type: integer. Units: browser pointer identifier. Scope: album ordering event regression. Consumers: dispatchAlbumPointer(). Rationale: keep each synthetic gesture internally consistent without depending on a physical device ID; Value: 17. */
const ALBUM_POINTER_ID = 17;
/** Await one native mutation cycle until its delegated handler releases the manager. @returns {Promise<void>} Resolves after the current root is idle. */
async function waitForIdle() {
    const deadline = Date.now() + ACTION_TIMEOUT_MS;
    while (Date.now() < deadline) {
        if (document.querySelector('[data-admin-music]')?.dataset.busy !== '1') return;
        await new Promise(/** Continue after the bounded observation interval. @param {function():void} resolve Resume the idle check. @returns {void} Schedules one timer. */ resolve => setTimeout(resolve, ACTION_POLL_MS));
    }
    throw new Error('Music Manager mutation did not settle.');
}
/** Await a bounded browser-owned condition without busy polling. @param {function():boolean} isReady State predicate for the expected terminal result. @param {string} failure Message identifying the missing state. @returns {Promise<void>} Resolves when the predicate becomes true. */
async function waitForBrowserState(isReady, failure) {
    const deadline = Date.now() + SEARCH_RESULT_TIMEOUT_MS;
    while (Date.now() < deadline) {
        if (isReady()) return;
        await new Promise(/** Yield to network and DOM tasks before the next observation. @param {function():void} resolve Continue bounded polling. @returns {void} Schedules one timer. */ resolve => setTimeout(resolve, ACTION_POLL_MS));
    }
    throw new Error(failure);
}
/** Check that album members keep their drag handle and three icon actions on one row without constraining the title. @param {HTMLElement} manager Rendered production manager root. @returns {boolean} True when every member control is visible and inside its row. */
function albumMemberActionsFit(manager) {
    const members = Array.from(manager.querySelectorAll(
        '.admin-music-album-members > li',
    ));
    return members.length > 0 && members.every(/** Check one member's controls only; title wrapping is intentionally unconstrained. @param {HTMLLIElement} member Rendered album member row. @returns {boolean} True when its three icon buttons share one row. */ member => {
        const buttons = Array.from(member.querySelectorAll(
            'button:not([data-admin-music-album-drag-handle])',
        ));
        const dragHandle = member.querySelector(
            'button[data-admin-music-album-drag-handle]',
        );
        const dragStyle = dragHandle instanceof HTMLButtonElement
            ? getComputedStyle(dragHandle) : null;
        const row = member.getBoundingClientRect();
        const controls = buttons.map(button => button.getBoundingClientRect());
        const dragBounds = dragHandle?.getBoundingClientRect();
        return controls.length === 3
            && dragHandle instanceof HTMLButtonElement
            && dragBounds !== undefined
            && Boolean(dragHandle.getAttribute('aria-label')?.trim())
            && dragHandle.getAttribute('aria-grabbed') === 'false'
            && dragHandle.draggable
            && dragStyle?.touchAction === 'none'
            && dragStyle.cursor === 'grab'
            && dragBounds.width > 0
            && dragBounds.top === controls[0].top
            && dragBounds.left >= row.left
            && dragBounds.right <= row.right
            && controls.every(rect => rect.width > 0
                && rect.top === controls[0].top
                && rect.left >= row.left
                && rect.right <= row.right);
    });
}
/** Test whether two controls occupy the same visible row without imposing cosmetic pixel thresholds. @param {Element} first First control. @param {Element} second Second control. @returns {boolean} True when their vertical bounds overlap. */
function shareVerticalRow(first, second) {
    const firstBounds = first.getBoundingClientRect();
    const secondBounds = second.getBoundingClientRect();
    return Math.max(firstBounds.top, secondBounds.top)
        < Math.min(firstBounds.bottom, secondBounds.bottom);
}
/** Read one computed CSS animation time in milliseconds. @param {Element} target Animated popup element. @returns {number} Parsed single duration, or NaN for unsupported serialization. */
function animationDurationMilliseconds(target) {
    const value = getComputedStyle(target).animationDuration.trim();
    return Number.parseFloat(value) * (value.endsWith('ms') ? 1 : 1000);
}
/** Dispatch one coordinated pointer event using viewport coordinates. @param {Element} target Element receiving the synthetic pointer event. @param {'pointerdown'|'pointermove'|'pointerup'} type Pointer phase. @param {{x:number,y:number}} point CSS-pixel point in the viewport. @returns {boolean} Whether the event was canceled by the production handler. */
function dispatchAlbumPointer(target, type, point) {
    const pressed = type !== 'pointerup';
    return target.dispatchEvent(new PointerEvent(type, {
        bubbles: true,
        cancelable: true,
        pointerId: ALBUM_POINTER_ID,
        pointerType: 'mouse',
        isPrimary: true,
        clientX: point.x,
        clientY: point.y,
        button: type === 'pointermove' ? -1 : 0,
        buttons: pressed ? 1 : 0,
    }));
}
try {
    const freshMusicMarkup = document.querySelector('#mount').innerHTML;
    setupAdminMusic();
    setupAdminMusic();
    let root = document.querySelector('[data-admin-music]');
    const originalUrl = location.href;
    let expectedPageUrl = originalUrl;
    check(root && root.querySelectorAll('tbody tr').length === 2,
        'Production Music Manager markup did not render both catalog rows.');
    const searchForm = root.querySelector('form.admin-music-search');
    const reconcileForm = Array.from(root.querySelectorAll('form[data-admin-music-form]'))
        .find(/** Find the standalone catalog reconciliation form. @param {HTMLFormElement} form Rendered Admin write form. @returns {boolean} True when its action is reconcile. */ form =>
            form.querySelector('input[name="action"]')?.value === 'reconcile');
    const mergeForm = root.querySelector('#admin-music-merge');
    check(searchForm?.method.toLowerCase() === 'get'
        && searchForm.querySelector('input[name="page"]')?.value === 'admin_music'
        && searchForm.querySelector('input[name="album_id"]')?.value === '8'
        && searchForm.querySelector('input[name="sort"]')?.value === 'files'
        && searchForm.querySelector('input[name="direction"]')?.value === 'desc'
        && !searchForm.matches('[data-admin-music-form]')
        && reconcileForm instanceof HTMLFormElement
        && mergeForm instanceof HTMLFormElement
        && reconcileForm !== mergeForm
        && !reconcileForm.contains(mergeForm)
        && !mergeForm.contains(reconcileForm)
        && !searchForm.contains(reconcileForm)
        && !searchForm.contains(mergeForm),
        'Search, reconcile, and merge must remain intact separate forms with the Admin Music GET route.');
    const sortLinks = Array.from(root.querySelectorAll(
        '.admin-music-table-wrap thead a',
    ));
    check(sortLinks.length === 4
        && sortLinks.every(link => {
            const target = new URL(link.href);
            return target.searchParams.get('page') === 'admin_music'
                && target.searchParams.get('album_id') === '8'
                && target.searchParams.get('offset') === '0';
        }),
        'Prepared sort links must retain the selected album and reset the catalog offset.');
    const songsPanel = root.querySelector('#admin-music-tab-songs');
    const albumsPanel = root.querySelector('#admin-music-tab-albums');
    const songsTab = root.querySelector(
        '.admin-music-tabs [data-admin-tab-target="admin-music-tab-songs"]',
    );
    const albumsTab = root.querySelector(
        '.admin-music-tabs [data-admin-tab-target="admin-music-tab-albums"]',
    );
    const sharedTabsNavigation = root.querySelector('.admin-music-tabs');
    const sharedSongsPanel = songsPanel;
    const sharedAlbumsPanel = albumsPanel;
    check(songsPanel instanceof HTMLElement
        && albumsPanel instanceof HTMLElement
        && songsTab instanceof HTMLAnchorElement
        && albumsTab instanceof HTMLAnchorElement
        && songsTab.getAttribute('aria-selected') === 'true'
        && songsPanel.hidden === false
        && albumsPanel.hidden === true
        && new URL(albumsTab.href).searchParams.get('tab') === 'albums',
        'The shared Admin tab setup must initially show Songs and prepare the Albums URL.');
    check(document.documentElement.scrollWidth <= innerWidth,
        'The Music Manager overflows the responsive browser viewport.');
    check(innerWidth === 1280,
        'The initial browser viewport must exercise the desktop layout.');
    const titleLine = root.querySelector('tbody tr .admin-music-title-line');
    const titleSelection = titleLine?.querySelector(
        '.admin-music-select input[name="song_ids[]"]',
    );
    const titleAction = titleLine?.querySelector(
        '[data-admin-music-title-edit]',
    );
    check(titleSelection instanceof HTMLInputElement
        && titleAction instanceof HTMLElement
        && titleAction.tagName === 'SUMMARY'
        && titleAction.textContent.trim() === 'Midnight Drive'
        && shareVerticalRow(titleSelection, titleAction),
        'Desktop title selection and native title summary must share one row.');
    const mergeDisclosure = mergeForm.closest('details.admin-music-merge');
    const mergeSummary = mergeDisclosure?.querySelector(':scope > summary');
    const reconcileButton = reconcileForm?.querySelector('button');
    check(mergeSummary instanceof HTMLElement
        && reconcileButton instanceof HTMLButtonElement
        && shareVerticalRow(searchForm, reconcileButton)
        && shareVerticalRow(searchForm, mergeSummary),
        'Desktop search, import, and merge controls must share one toolbar row.');
    albumsTab.click();
    const returnTabInputs = Array.from(
        root.querySelectorAll('form input[name="return_tab"]'),
    );
    check(albumsTab.getAttribute('aria-selected') === 'true'
        && albumsPanel.hidden === false
        && songsPanel.hidden === true
        && new URL(location.href).searchParams.get('tab') === 'albums'
        && returnTabInputs.length > 0
        && returnTabInputs.every(input => input.value === 'admin-music-tab-albums'),
        'Selecting Albums must use the shared URL mode, switch panels, and update every hidden return_tab.');
    const albumDetail = root.querySelector('.admin-music-album-detail');
    const albumContext = albumDetail?.querySelector(
        ':scope > header.admin-music-album-heading > details.admin-music-album-options',
    );
    const albumContextForms = Array.from(albumContext?.querySelectorAll('form') || []);
    const albumContextFields = albumContext?.querySelector(
        ':scope > .admin-music-album-fields',
    );
    const albumContextState = {
        foundDetails: albumContext instanceof HTMLDetailsElement,
        open: albumContext?.open ?? null,
        ariaLabel: albumContext?.querySelector(':scope > summary')
            ?.getAttribute('aria-label') ?? null,
        actions: albumContextForms.map(form =>
            form.querySelector('input[name="action"]')?.value ?? null,
        ),
        formClientRectCounts: albumContextForms.map(form =>
            form.getClientRects().length,
        ),
        fieldsDisplay: albumContextFields
            ? getComputedStyle(albumContextFields).display
            : null,
    };
    check(albumContextState.foundDetails
        && albumContextState.open === false
        && albumContextState.ariaLabel === 'Album settings'
        && albumContextState.actions.join(',')
            === 'album_update,album_move,album_delete'
        && albumContextState.fieldsDisplay === 'none'
        && albumContextState.formClientRectCounts.every(count => count === 0),
        'Album context must start closed with hidden update/move/delete forms: '
            + JSON.stringify(albumContextState));
    check(albumMemberActionsFit(root),
        'Desktop album member icon actions must fit one row while titles may wrap.');
    songsTab.click();
    check(songsTab.getAttribute('aria-selected') === 'true'
        && songsPanel.hidden === false
        && albumsPanel.hidden === true
        && new URL(location.href).searchParams.get('tab') === 'songs',
        'Returning to Songs must restore its panel and standalone URL state.');
    window.__adminMusicDesktopReady = true;
    await new Promise(/** Pause before actions while the harness captures both layouts. @param {function():void} resolve Resume after viewport evidence. @returns {void} Retains the initial page state. */ resolve => {
        window.__adminMusicContinueAfterScreenshots = resolve;
    });
    check(innerWidth === 390
        && document.documentElement.scrollWidth <= innerWidth,
        'The Music Manager overflows the 390px mobile viewport.');
    root.querySelector(
        '.admin-music-tabs [data-admin-tab-target="admin-music-tab-albums"]',
    )?.click();
    check(root.querySelector('#admin-music-tab-albums')?.hidden === false,
        'The Albums panel must remain switchable after desktop and mobile layout replacement.');
    check(albumMemberActionsFit(root),
        'Mobile album member icon actions must fit one row while titles may wrap.');
    const albumContextDisclosure = root.querySelector(
        ':scope .admin-music-album-detail > header.admin-music-album-heading > details.admin-music-album-options',
    );
    const albumContextSummary = albumContextDisclosure?.querySelector(':scope > summary');
    albumContextSummary.click();
    check(albumContextDisclosure.open
        && albumContextForms.every(form => form.getClientRects().length > 0)
        && albumContext.querySelector('input[name="album_title"]')?.value === 'Night Drives'
        && albumContext.querySelector('select[name="parent_id"]')
        && albumContext.querySelector('button[aria-label="Delete album"]')
        && document.documentElement.scrollWidth <= innerWidth,
        'Opening album context actions must expose metadata, move and delete controls without mobile overflow.');
    albumContextSummary.click();
    check(!albumContextDisclosure.open
        && albumContextForms.every(form => form.getClientRects().length === 0),
        'The album context gear must collapse its metadata and destructive forms again.');
    root.querySelector(
        '.admin-music-tabs [data-admin-tab-target="admin-music-tab-songs"]',
    )?.click();
    check(root.querySelector('#admin-music-tab-songs')?.hidden === false,
        'The Songs panel must remain switchable after the narrow Albums interaction.');
    check(root.querySelectorAll('svg[aria-hidden="true"]').length >= 8,
        'Icon controls must use decorative production SVGs.');
    check(Array.from(root.querySelectorAll('button')).every(/** Check one manager action's accessible name. @param {HTMLButtonElement} button Rendered action button. @returns {boolean} True when the action has a name. */ button =>
        button.getAttribute('aria-label') || button.textContent.trim()),
        'An icon action has no accessible name.');
    const firstAction = root.querySelector('.admin-music-preferences > summary');
    firstAction.focus();
    check(document.activeElement === firstAction
        && getComputedStyle(firstAction).outlineStyle !== 'none',
        'Keyboard focus is not visibly indicated on an icon action.');

    const rows = root.querySelectorAll('tbody tr');
    check(Array.from(rows).every(/** Check one catalog row's visible tag action. @param {HTMLTableRowElement} row Rendered song row. @returns {boolean} True when its tag control is visible. */ row => {
        const tagAction = row.querySelector('.admin-music-edit summary[aria-label="Tags"]');
        return tagAction && tagAction.getBoundingClientRect().width > 0;
    }), 'A song tag action is hidden at this viewport width.');
    const usageDetails = rows[0].children[2].querySelector(
        'details.admin-music-locations',
    );
    const usageSummary = usageDetails.querySelector(':scope > summary');
    check(usageSummary.textContent.trim() === '3',
        'Collapsed usage summary must show only its numeric count.');
    const variants = rows[0].children[1].querySelector(
        'details.admin-music-variants',
    );
    const variantsSummary = variants.querySelector(':scope > summary');
    check(variantsSummary.textContent.trim() === '3'
        && variantsSummary.querySelector('.subgallery-stack-count')
            ?.textContent.trim() === '3'
        && variantsSummary.querySelector('.subgallery-stack-icon')
            ?.getAttribute('aria-hidden') === 'true'
        && variantsSummary.querySelector('.subgallery-stack-icon')
            ?.children.length === 3
        && variantsSummary.getAttribute('aria-label') === 'Files',
        'Variant disclosure must expose only its numeric badge and retain its production Files accessible label.');
    check(Array.from(variants.querySelectorAll('li a')).length === 1
        && Array.from(variants.querySelectorAll('li a')).every(link =>
            new URL(link.href).pathname.startsWith('/gallery/')),
        'Physical variant locations must use prepared public gallery URLs.');
    const variantRows = Array.from(variants.querySelectorAll(':scope > ul > li'));
    const promotedFilenameRows = variantRows.filter(row =>
        row.textContent.includes('midnight-live.mp3'));
    const repositoryOnlyRow = variantRows.find(row =>
        row.textContent.includes('repository-only.wav'));
    const promotedRepositoryRow = variantRows.find(row =>
        row.textContent.includes('Shared repository file')
            && row.textContent.includes('midnight-live.mp3'));
    check(variantRows.length === 3
        && promotedFilenameRows.length === 1
        && promotedRepositoryRow !== undefined
        && repositoryOnlyRow !== undefined
        && repositoryOnlyRow.textContent.includes('4096 bytes')
        && repositoryOnlyRow.querySelector('a') === null,
        'File details must include repository-only assets and byte size while deduplicating a promoted origin; rows=' + JSON.stringify(variantRows.map(row => row.textContent.trim())) + '.');
    const motionStyle = document.documentElement.style;
    const previousMotionDuration = motionStyle.getPropertyValue(
        '--admin-side-panel-motion-duration',
    );
    try {
        motionStyle.setProperty(
            '--admin-side-panel-motion-duration',
            THEME_SIDE_PANEL_MOTION_DEFAULT,
        );
        usageSummary.click();
        const usagePopup = usageDetails.querySelector(':scope > ul');
        const usageAnimation = getComputedStyle(usagePopup).animationDuration;
        check(animationDurationMilliseconds(usagePopup)
            === Number.parseFloat(THEME_SIDE_PANEL_MOTION_DEFAULT),
            'Usage popup should inherit the shared Admin motion duration '
                + THEME_SIDE_PANEL_MOTION_DEFAULT + '; computed '
                + usageAnimation + '.');
        const usageLinks = Array.from(
            usageDetails.querySelectorAll(':scope > ul a'),
        );
        const directPublicLink = usageLinks.find(link =>
            link.textContent.trim() === 'Highway Set');
        const livePublicLink = usageLinks.find(link =>
            link.textContent.trim() === 'Road Mix Live');
        const albumUsageLink = usageLinks.find(link =>
            link.textContent.includes('Night Drives'));
        const albumUsageUrl = albumUsageLink
            ? new URL(albumUsageLink.href) : null;
        check(rows[0].children[2].textContent.includes('Highway Set')
            && rows[0].children[2].textContent.includes('Night Drives')
            && rows[0].children[2].textContent.includes('Road Mix Live')
            && directPublicLink !== undefined
            && new URL(directPublicLink.href).pathname === '/gallery/highway-set'
            && livePublicLink !== undefined
            && new URL(livePublicLink.href).pathname === '/gallery/road-mix-live'
            && albumUsageUrl?.searchParams.get('page') === 'admin_music'
            && albumUsageUrl.searchParams.get('tab') === 'albums'
            && albumUsageUrl.searchParams.get('album_id') === '8',
            'Expanded usage must render direct and live source placements as public gallery links and retain album usage.');

        motionStyle.setProperty('--admin-side-panel-motion-duration', '0ms');
        variantsSummary.click();
        const variantsPopup = variants.querySelector(':scope > ul');
        const variantsAnimation = getComputedStyle(variantsPopup).animationDuration;
        check(animationDurationMilliseconds(variantsPopup) === 0,
            'Variant popup must honor the shared zero-motion setting; computed '
                + variantsAnimation + '.');
        check(variants.textContent.includes('midnight-live.mp3')
            && variants.textContent.includes('midnight-studio.mp3'),
            'Variant disclosure lost physical album paths.');
    } finally {
        if (previousMotionDuration === '') {
            motionStyle.removeProperty('--admin-side-panel-motion-duration');
        } else {
            motionStyle.setProperty(
                '--admin-side-panel-motion-duration',
                previousMotionDuration,
            );
        }
    }
    const titleDisclosureForContext = rows[0].querySelector(
        'details.admin-music-song-edit',
    );
    const titleContextPopover = titleDisclosureForContext?.querySelector(
        ':scope > .admin-music-song-popover',
    );
    for (const [context, selector] of [
        ['tags', '.admin-music-tags'],
        ['files', '.admin-music-variants'],
        ['locations', '.admin-music-locations'],
    ]) {
        rows[0].querySelector('[data-admin-music-title-edit]').click();
        titleContextPopover.querySelector(
            '[data-admin-music-context="' + context + '"]',
        ).click();
        const contextDisclosure = rows[0].querySelector(selector);
        check(titleDisclosureForContext.open === false
            && contextDisclosure instanceof HTMLDetailsElement
            && contextDisclosure.open === true
            && contextDisclosure.querySelector(':scope > summary')
                === document.activeElement,
            'The ' + context + ' shortcut must close the title popover, open its row detail, and move focus to that disclosure.');
        contextDisclosure.querySelector(':scope > summary').click();
    }
    const titleEditorInput = rows[0].querySelector('textarea[name="title"]');
    const titleEditorDisclosure = titleEditorInput?.closest('details');
    const titleEditorForm = titleEditorInput?.form;
    const titleEditorPopup = titleEditorInput?.closest(
        '.admin-music-song-popover',
    );
    const mergeSelection = rows[0].querySelector(
        'input[name="song_ids[]"]',
    );
    const titleActionButton = rows[0].querySelector(
        '[data-admin-music-title-edit]',
    );
    root.dataset.busy = '1';
    titleActionButton.click();
    check(titleEditorDisclosure?.open === false,
        'Title editor must not open while a catalog write is busy.');
    root.dataset.busy = '0';
    root.dataset.stale = '1';
    titleActionButton.click();
    check(titleEditorDisclosure?.open === false,
        'Title editor must not open while catalog synchronization is stale.');
    delete root.dataset.stale;
    titleActionButton.dispatchEvent(new MouseEvent('click', {
        bubbles: true,
        cancelable: true,
        detail: 1,
        clientX: window.innerWidth - 1,
        clientY: window.innerHeight - 1,
    }));
    await Promise.all(titleEditorPopup.getAnimations().map(
        animation => animation.finished.catch(() => undefined),
    ));
    const pointerEditorBounds = titleEditorPopup.getBoundingClientRect();
    const pointerEditorHit = document.elementFromPoint(
        pointerEditorBounds.left + pointerEditorBounds.width / 2,
        pointerEditorBounds.top + pointerEditorBounds.height / 2,
    );
    const expectedPointerLeft = Math.min(
        window.innerWidth - pointerEditorBounds.width
            - TITLE_EDITOR_VIEWPORT_MARGIN_PX,
        Math.max(
            TITLE_EDITOR_VIEWPORT_MARGIN_PX,
            window.innerWidth - 1 + TITLE_EDITOR_GUTTER_PX,
        ),
    );
    const expectedPointerTop = Math.min(
        window.innerHeight - pointerEditorBounds.height
            - TITLE_EDITOR_VIEWPORT_MARGIN_PX,
        Math.max(
            TITLE_EDITOR_VIEWPORT_MARGIN_PX,
            window.innerHeight - 1 + TITLE_EDITOR_GUTTER_PX,
        ),
    );
    check(titleEditorDisclosure?.open === true
        && document.activeElement === titleEditorInput
        && titleEditorInput?.selectionStart === titleEditorInput?.selectionEnd
        && titleEditorPopup?.classList.contains('admin-music-title-pointer-editor')
        && Math.abs(pointerEditorBounds.left - expectedPointerLeft) < 1
        && Math.abs(pointerEditorBounds.top - expectedPointerTop) < 1
        && pointerEditorBounds.left >= 0
        && pointerEditorBounds.top >= 0
        && pointerEditorBounds.right <= window.innerWidth
        && pointerEditorBounds.bottom <= window.innerHeight
        && pointerEditorHit instanceof Element
        && titleEditorPopup.contains(pointerEditorHit)
        && mergeSelection?.checked === false,
        'Pointer title action must open a collapsed-caret editor near the click, clamp it into the viewport, and preserve merge selection.');
    const originalTitleValue = titleEditorInput.value;
    const initialTitleFieldHeight = titleEditorInput.clientHeight;
    const initialTitleEditorWidth = titleEditorPopup.style
        .getPropertyValue('--music-title-editor-width');
    titleEditorInput.value = 'A long wrapped title that should use more than one visual line '.repeat(3);
    titleEditorInput.dispatchEvent(new Event('input', {bubbles: true}));
    const grownTitleEditorBounds = titleEditorPopup.getBoundingClientRect();
    const grownTitleFieldBounds = titleEditorInput.getBoundingClientRect();
    check(titleEditorPopup.style.getPropertyValue('--music-title-editor-width')
            !== initialTitleEditorWidth
        && grownTitleFieldBounds.height > 0
        && titleEditorInput.clientHeight > initialTitleFieldHeight
        && titleEditorInput.clientHeight > 0
        && titleEditorInput.scrollHeight <= titleEditorInput.clientHeight
        && grownTitleEditorBounds.right <= window.innerWidth
        && grownTitleEditorBounds.bottom <= window.innerHeight,
        'Long title text must grow the editor width while keeping the popup within the viewport.');
    titleEditorInput.value = originalTitleValue;
    titleEditorInput.dispatchEvent(new Event('input', {bubbles: true}));
    titleActionButton.click();
    check(titleEditorDisclosure?.open === false,
        'Clicking the active title a second time must close its editor.');
    titleActionButton.click();
    root.querySelector('h1, h2, h3')?.dispatchEvent(new MouseEvent('click', {
        bubbles: true,
        cancelable: true,
    }));
    check(titleEditorDisclosure?.open === false,
        'Clicking outside the title editor must close it.');
    titleActionButton.click();
    const otherTitleAction = rows[1]?.querySelector('[data-admin-music-title-edit]');
    otherTitleAction?.click();
    check(titleEditorDisclosure?.open === false
        && rows[1]?.querySelector('textarea[name="title"]')?.closest('details.admin-music-song-edit')?.open === true,
        'Opening another title must close the previous editor and open the selected row.');
    root.querySelector('h1, h2, h3')?.dispatchEvent(new MouseEvent('click', {
        bubbles: true,
        cancelable: true,
    }));
    check(root.querySelector('.admin-music-album-tree a[aria-current="page"]')
        ?.textContent.trim() === 'Night Drives'
        && root.querySelector('.admin-music-album-detail nav')?.textContent.includes('Road Trips'),
        'Nested album selection or breadcrumb path is missing.');
    check(root.querySelector('.admin-music-album-tree a[aria-current="page"]')
        ?.closest('li')?.querySelector('span')?.textContent.trim() === '2',
        'Album tree did not render its prepared song count.');
    const split = rows[0].querySelector('.admin-music-split');
    split.querySelector('summary').click();
    const selectedSplitTracks = Array.from(
        split.querySelectorAll('input[name="track_ids[]"]:checked'),
        /** Preserve one selected composite track identifier. @param {HTMLInputElement} input Selected split checkbox. @returns {string} Gallery and track identity. */ input => input.value,
    );
    check(selectedSplitTracks.length === 1
        && selectedSplitTracks[0] === '42:a2',
        'Split selection did not preserve the composite gallery and track identity.');
    const memberRows = root.querySelectorAll('.admin-music-album-detail > ul > li');
    check(memberRows.length === 2
        && memberRows[0].textContent.includes('Midnight Drive')
        && memberRows[1].textContent.includes('Quiet Morning'),
        'Selected album membership ordering is missing.');
    check(memberRows[0].querySelector('input[name="position"]')?.value === '0',
        'Album member order control did not retain its prepared position.');
    check(Array.from(memberRows[1].querySelectorAll('input[name="position"]'))
        .map(input => input.value).join(',') === '0,2',
        'The second album member must move above the first or after its current position.');
    check(Array.from(memberRows[0].querySelectorAll('form[data-admin-music-form]'))
        .map(form => form.querySelector('input[name="action"]')?.value)
        .join(',') === 'album_reorder_song,album_reorder_song,album_remove_song',
        'Native drag ordering must retain the existing move-up, move-down, and remove form actions.');

    const calls = [];
    const searchRequests = [];
    const writeBodies = [];
    let releaseOlderSearch = null;
    let releaseTitleRace = null;
    let releaseTagRace = null;
    let olderSearchBodyRead = false;
    let releaseMutation = null;
    let mode = 'hold-success';
    let confirmText = '';
    window.confirm = /** Capture the real merge confirmation text. @param {string} question Production confirmation contents. @returns {boolean} Accept the fixture action. */ question => { confirmText = question; return true; };
    const nativeFetch = window.fetch.bind(window);
    window.fetch = /** Mock canonical Music Manager writes and route reads through the production PHP view fixture. @param {RequestInfo|URL} input Production request URL. @param {RequestInit} options Request method, body, credentials and headers. @returns {Promise<Response>} Canonical mutation envelope, rendered search fragment, or synchronization failure. */ async (input, options = {}) => {
        const url = String(input);
        calls.push({url, method: options.method || 'GET'});
        if (options.method === 'POST') {
            const body = options.body;
            writeBodies.push({
                action: body.get('action'),
                title: body.get('title'),
                albumTitle: body.get('album_title'),
                songId: body.get('song_id'),
                albumId: body.get('album_id'),
                position: body.get('position'),
                returnTab: body.get('return_tab'),
            });
            check(url === '/index.php?page=admin_music', 'Mutation used an unexpected endpoint.');
            check(options.credentials === 'same-origin'
                && options.headers['X-Requested-With'] === 'XMLHttpRequest'
                && body.get('csrf_token') === 'music-fixture'
                && body.get('ajax') === '1',
                'Mutation lost its protected same-origin canonical request.');
            if (mode === 'reject') {
                return new Response(JSON.stringify({ok: false, message: 'Fixture rejected'}),
                    {status: 409, headers: {'Content-Type': 'application/json'}});
            }
            const envelope = normalizeAdminMutationEnvelope({
                ok: true,
                message: 'Music saved successfully.',
                mutation: {type: 'music.catalog', entity: 'song', action: 'update', entity_ids: [101]},
                panel: {workflow: 'admin-music', refresh_url: '/music-fragment', keep_open: true},
                contexts: [{type: 'gallery', gallery_id: 41, render_url: '/gallery/41'}],
                fallback: {url: '/index.php?page=admin_music'},
            });
            check(envelope.mutation.entity_ids[0] === 101,
                'The canonical mutation identity was changed before completion.');
            const mutationResponse = new Response(JSON.stringify(envelope),
                {headers: {'Content-Type': 'application/json'}});
            if (mode === 'hold-success') {
                return new Promise(resolve => {
                    releaseMutation = () => resolve(mutationResponse);
                });
            }
            return mutationResponse;
        }
        if (url === '/music-fragment') {
            if (mode === 'unsynchronized') return new Response('', {status: 503});
            return new Response(freshMusicMarkup,
                {headers: {'Content-Type': 'text/html'}});
        }
        const requestUrl = new URL(url, location.href);
        check(requestUrl.pathname === '/index.php'
            && requestUrl.searchParams.get('page') === 'admin_music'
            && requestUrl.searchParams.has('q')
            && requestUrl.searchParams.get('fragment') === '1'
            && options.credentials === 'same-origin'
            && options.headers['Accept'] === 'text/html',
            'Unexpected read or coordinator endpoint: ' + url);
        const query = requestUrl.searchParams.get('q') || '';
        searchRequests.push({url, query, signal: options.signal});
        if (query === 'Midnight') {
            return new Promise(resolve => { releaseOlderSearch = resolve; });
        }
        if (query === 'TitleRace') {
            return new Promise(resolve => { releaseTitleRace = resolve; });
        }
        if (query === 'TagRace') {
            return new Promise(resolve => { releaseTagRace = resolve; });
        }
        if (query === 'Broken') return new Response('', {status: 503});
        const renderUrl = '/music-search-render?'
            + requestUrl.searchParams.toString();
        return nativeFetch(renderUrl, {
            credentials: options.credentials,
            headers: options.headers,
            signal: options.signal,
        });
    };

    const extensions = root.querySelector('input[name="show_extensions"]');
    extensions.checked = true;
    extensions.form.requestSubmit(extensions.form.querySelector('button'));
    extensions.form.dispatchEvent(new SubmitEvent('submit', {
        bubbles: true,
        cancelable: true,
        submitter: extensions.form.querySelector('button'),
    }));
    check(calls.filter(/** Count writes already delivered to the mock transport. @param {{url:string,method:string}} call Captured request summary. @returns {boolean} True for a mutation. */ call => call.method === 'POST').length === 1,
        'A repeated in-flight submit sent a duplicate write.');
    check(root.dataset.busy === '1'
        && typeof releaseMutation === 'function',
        'The first write must own the manager while its response is pending.');
    const queuedSearchInput = root.querySelector(
        'form.admin-music-search input[name="q"]',
    );
    queuedSearchInput.focus();
    queuedSearchInput.value = 'i';
    queuedSearchInput.setSelectionRange(1, 1);
    queuedSearchInput.dispatchEvent(new Event('input', {bubbles: true}));
    check(root.dataset.busy === '1'
        && queuedSearchInput.value === 'i'
        && !searchRequests.some(request => request.query === 'i'),
        'Live search must queue its query without fetching or replacing an editor during a write.');
    releaseMutation();
    await waitForIdle();
    await waitForBrowserState(
        () => searchRequests.some(request => request.query === 'i')
            && document.querySelector('[data-admin-music] form.admin-music-search input[name="q"]')
                ?.value === 'i'
            && new URL(location.href).searchParams.get('q') === 'i',
        'The queued search did not resume after the canonical mutation refresh.',
    );
    root = document.querySelector('[data-admin-music]');
    check(root.querySelector('[role="status"]')?.textContent === 'Music saved successfully.'
        && root.querySelectorAll('tbody tr').length === 2,
        'Mutation notice or both rows were lost while resuming a queued live search.');
    expectedPageUrl = location.href;
    mode = 'success';
    check(calls.filter(/** Count one recorded POST operation. @param {{url:string,method:string}} call Captured request summary. @returns {boolean} True for a mutation. */ call => call.method === 'POST').length === 1
        && calls.some(/** Find the production panel refresh request. @param {{url:string,method:string}} call Captured request summary. @returns {boolean} True for the fixture refresh URL. */ call => call.url === '/music-fragment'),
        'The production mutation coordinator did not refresh the panel exactly once.');

    const merge = root.querySelector('#admin-music-merge');
    root.querySelector('input[name="song_ids[]"][value="101"]').checked = true;
    root.querySelector('input[name="song_ids[]"][value="102"]').checked = true;
    merge.querySelector('select[name="song_id"]').value = '101';
    merge.querySelector('select[name="tag_policy"]').value = 'union';
    merge.requestSubmit(merge.querySelector('button'));
    await waitForIdle();
    check(confirmText.includes('Midnight Drive') && confirmText.includes('ambient, road')
        && confirmText.includes('midnight-live.mp3') && confirmText.includes('Highway Set')
        && confirmText.includes('Quiet Morning') && confirmText.includes('Night Drives')
        && confirmText.includes('Used:')
        && confirmText.includes('The chosen song keeps its title; tags are combined.'),
        'Merge confirmation omitted selected titles, tags, files, usage locations, or union policy.');
    check(calls.filter(/** Count both captured mutation operations. @param {{url:string,method:string}} call Captured request summary. @returns {boolean} True for a mutation. */ call => call.method === 'POST').length === 2
        && location.href === expectedPageUrl,
        'The refreshed production form was not intercepted once in place.');

    root = document.querySelector('[data-admin-music]');
    root.querySelector('[data-admin-music-title-edit]')?.click();
    const titleInput = root.querySelector('textarea[name="title"]');
    const titleForm = titleInput.form;
    check(titleInput.closest('details')?.open === true
        && document.activeElement === titleInput
        && titleInput.selectionStart === titleInput.selectionEnd
        && root.querySelector('input[name="song_ids[]"][value="101"]')?.checked === false,
        'The refreshed title action must open a collapsed-caret editor without changing selected merge rows.');
    titleInput.value = 'Retained on rejection';
    mode = 'reject';
    const modifiedEnter = new KeyboardEvent('keydown', {
        key: 'Enter',
        shiftKey: true,
        bubbles: true,
        cancelable: true,
    });
    titleInput.dispatchEvent(modifiedEnter);
    check(modifiedEnter.defaultPrevented
        && titleInput.value === 'Retained on rejection'
        && calls.filter(call => call.method === 'POST').length === 2,
        'Modified Enter must not insert a line break or submit a separate title identity.');
    const enterSubmission = new KeyboardEvent('keydown', {
        key: 'Enter',
        bubbles: true,
        cancelable: true,
    });
    titleInput.dispatchEvent(enterSubmission);
    await waitForIdle();
    check(root.querySelector('[role="alert"]')?.textContent === 'Fixture rejected'
        && titleForm.querySelector('textarea[name="title"]')?.value
            === 'Retained on rejection'
        && writeBodies.at(-1)?.title === 'Retained on rejection'
        && enterSubmission.defaultPrevented
        && location.href === expectedPageUrl,
        'Plain Enter must submit a one-line title, and a rejection must retain the editor value without navigating.');

    root = document.querySelector('[data-admin-music]');
    const addSongTitle = root.querySelector(
        'tbody tr input[name="song_ids[]"][value="102"]',
    ).closest('tr').querySelector('[data-admin-music-title-edit]');
    addSongTitle.click();
    const addSongDetails = addSongTitle.closest(
        'details.admin-music-song-edit',
    );
    const addSongPopover = addSongDetails.querySelector(
        '.admin-music-song-popover',
    );
    const addSongForm = addSongPopover.querySelector(
        'form.admin-music-album-add-song',
    );
    const addSongAlbum = addSongForm?.querySelector('select[name="album_id"]');
    check(addSongForm instanceof HTMLFormElement
        && addSongForm.querySelector('input[name="action"]')?.value
            === 'album_add_song'
        && addSongForm.querySelector('input[name="song_id"]')?.value === '102'
        && addSongForm.querySelector('input[name="position"]')?.value === ''
        && addSongAlbum instanceof HTMLSelectElement
        && addSongForm.closest('form') === addSongForm
        && addSongDetails.open,
        'The title popover must contain a separate canonical add-to-album form without nesting forms.');
    addSongAlbum.value = '10';
    check(addSongDetails.open,
        'Choosing an album inside the title popover must not trigger outside-click closing.');
    mode = 'success';
    const pageBeforeAlbumAdd = location.href;
    const postsBeforeAlbumAdd = calls.filter(call => call.method === 'POST').length;
    addSongForm.requestSubmit(addSongForm.querySelector('button[type="submit"]'));
    await waitForIdle();
    const postsAfterAlbumAdd = calls.filter(call => call.method === 'POST').length;
    const addSongState = {
        postsBefore: postsBeforeAlbumAdd,
        postsAfter: postsAfterAlbumAdd,
        lastWrite: writeBodies.at(-1),
        currentUrl: location.href,
        expectedUrl: pageBeforeAlbumAdd,
        songsHidden: document.querySelector(
            '[data-admin-music] #admin-music-tab-songs',
        )?.hidden,
    };
    check(postsAfterAlbumAdd === postsBeforeAlbumAdd + 1
        && writeBodies.at(-1)?.action === 'album_add_song'
        && writeBodies.at(-1)?.songId === '102'
        && writeBodies.at(-1)?.albumId === '10'
        && writeBodies.at(-1)?.position === ''
        && writeBodies.at(-1)?.returnTab === 'admin-music-tab-songs'
        && location.href === pageBeforeAlbumAdd
        && document.querySelector('[data-admin-music] #admin-music-tab-songs')
            ?.hidden === false,
        'Add-to-album must use the canonical coordinator, preserve the selected '
            + 'tab, and avoid navigation: ' + JSON.stringify(addSongState));

    root = document.querySelector('[data-admin-music]');
    root.querySelector(
        '.admin-music-tabs [data-admin-tab-target="admin-music-tab-albums"]',
    ).click();
    const albumCreateForm = root.querySelector('form.admin-music-new-album');
    albumCreateForm.querySelector('input[name="album_title"]').value
        = 'Fixture album';
    const pageBeforeAlbumCreate = location.href;
    const postsBeforeAlbumCreate = calls.filter(
        call => call.method === 'POST',
    ).length;
    albumCreateForm.requestSubmit(albumCreateForm.querySelector('button[type="submit"]'));
    await waitForIdle();
    root = document.querySelector('[data-admin-music]');
    const postsAfterAlbumCreate = calls.filter(
        call => call.method === 'POST',
    ).length;
    const albumCreateState = {
        postsBefore: postsBeforeAlbumCreate,
        postsAfter: postsAfterAlbumCreate,
        lastWrite: writeBodies.at(-1),
        albumsHidden: root.querySelector('#admin-music-tab-albums')?.hidden,
        songsHidden: root.querySelector('#admin-music-tab-songs')?.hidden,
        returnTabs: Array.from(
            root.querySelectorAll('form input[name="return_tab"]'),
            input => input.value,
        ),
        currentUrl: location.href,
        expectedUrl: pageBeforeAlbumCreate,
    };
    check(postsAfterAlbumCreate === postsBeforeAlbumCreate + 1
        && writeBodies.at(-1)?.action === 'album_create'
        && writeBodies.at(-1)?.albumTitle === 'Fixture album'
        && writeBodies.at(-1)?.returnTab === 'admin-music-tab-albums'
        && root.querySelector('#admin-music-tab-albums')?.hidden === false
        && root.querySelector('#admin-music-tab-songs')?.hidden === true
        && Array.from(root.querySelectorAll('form input[name="return_tab"]'))
            .every(input => input.value === 'admin-music-tab-albums')
        && location.href === pageBeforeAlbumCreate,
        'A refreshed Albums mutation must retain the shared panel node, '
            + 'selected tab, return fields, and current URL: '
            + JSON.stringify(albumCreateState));

    root = document.querySelector('[data-admin-music]');
    const albumMembers = root.querySelector(
        '[data-admin-music-album-members][data-album-id="8"]',
    );
    const dragSource = albumMembers?.querySelector('li[data-song-id="101"]');
    const dragTarget = albumMembers?.querySelector('li[data-song-id="102"]');
    const dragHandle = dragSource?.querySelector(
        '[data-admin-music-album-drag-handle]',
    );
    const reorderPosition = dragSource?.querySelector(
        'form input[name="action"][value="album_reorder_song"]',
    )?.closest('form')?.querySelector('input[name="position"]');
    check(albumMembers instanceof HTMLUListElement
        && albumMembers.dataset.albumOffset === '0'
        && dragSource?.dataset.position === '0'
        && dragTarget?.dataset.position === '1'
        && dragHandle instanceof HTMLButtonElement
        && dragHandle.draggable,
        'The prepared album list must expose absolute member positions and a native drag handle.');
    dragHandle.scrollIntoView({block: 'center'});
    const dragSourceBounds = dragHandle.getBoundingClientRect();
    const dragTargetBounds = dragTarget.getBoundingClientRect();
    const dragStart = {
        x: dragSourceBounds.left + dragSourceBounds.width / 2,
        y: dragSourceBounds.top + dragSourceBounds.height / 2,
    };
    // The lower-quarter point is beyond the row midpoint and selects the after side.
    const dragEnd = {
        x: dragTargetBounds.left + dragTargetBounds.width / 2,
        y: dragTargetBounds.top + dragTargetBounds.height * 0.75,
    };
    let capturedPointerId = null;
    let lastCaptureRequest = null;
    let releasedPointerId = null;
    Object.defineProperty(dragHandle, 'setPointerCapture', {
        configurable: true,
        value: /** Model pointer capture for synthetic events without a hardware pointer. @param {number} pointerId Production pointer identifier requested for capture. @returns {void} Records the capture request. */ pointerId => {
            capturedPointerId = pointerId;
            lastCaptureRequest = pointerId;
        },
    });
    Object.defineProperty(dragHandle, 'hasPointerCapture', {
        configurable: true,
        value: /** Report fixture-owned capture state to the production cleanup path. @param {number} pointerId Pointer identifier queried by production. @returns {boolean} Whether this fixture currently owns it. */ pointerId => capturedPointerId === pointerId,
    });
    Object.defineProperty(dragHandle, 'releasePointerCapture', {
        configurable: true,
        value: /** Model release of the fixture-owned pointer. @param {number} pointerId Pointer identifier released by production. @returns {void} Records and clears the capture state. */ pointerId => {
            releasedPointerId = pointerId;
            capturedPointerId = null;
        },
    });
    const writesBeforePointer = calls.filter(call => call.method === 'POST').length;
    root.dataset.busy = '1';
    dispatchAlbumPointer(dragHandle, 'pointerdown', dragStart);
    dispatchAlbumPointer(dragTarget, 'pointermove', dragEnd);
    dispatchAlbumPointer(dragTarget, 'pointerup', dragEnd);
    root.dataset.busy = '0';
    root.dataset.stale = '1';
    dispatchAlbumPointer(dragHandle, 'pointerdown', dragStart);
    dispatchAlbumPointer(dragTarget, 'pointermove', dragEnd);
    dispatchAlbumPointer(dragTarget, 'pointerup', dragEnd);
    delete root.dataset.stale;
    check(calls.filter(call => call.method === 'POST').length === writesBeforePointer
        && capturedPointerId === null
        && lastCaptureRequest === null,
        'Busy and stale album roots must reject pointer reorder starts without writing or capturing the pointer.');

    dispatchAlbumPointer(dragHandle, 'pointerdown', dragStart);
    // A one-pixel move on each axis remains below the production drag threshold.
    const clickRelease = {x: dragStart.x + 1, y: dragStart.y + 1};
    dispatchAlbumPointer(dragHandle, 'pointerup', clickRelease);
    check(calls.filter(call => call.method === 'POST').length === writesBeforePointer
        && capturedPointerId === null
        && lastCaptureRequest === ALBUM_POINTER_ID
        && releasedPointerId === ALBUM_POINTER_ID,
        'A handle click that stays below the drag threshold must cancel without a reorder write.');

    const sourceBounds = dragSource.getBoundingClientRect();
    // This in-row target crosses the movement threshold but must remain a no-op.
    const sameRowPoint = {
        x: sourceBounds.left + sourceBounds.width * 0.75,
        y: sourceBounds.top + sourceBounds.height * 0.75,
    };
    dispatchAlbumPointer(dragHandle, 'pointerdown', dragStart);
    dispatchAlbumPointer(dragSource, 'pointermove', sameRowPoint);
    dispatchAlbumPointer(dragSource, 'pointerup', sameRowPoint);
    check(calls.filter(call => call.method === 'POST').length === writesBeforePointer
        && dragHandle.getAttribute('aria-grabbed') === 'false',
        'Releasing a moved pointer over its own row must clear the drag without submitting a no-op.');

    mode = 'success';
    dispatchAlbumPointer(dragHandle, 'pointerdown', dragStart);
    dispatchAlbumPointer(dragTarget, 'pointermove', dragEnd);
    check(dragTarget.dataset.dropPosition === 'after'
        && dragHandle.getAttribute('aria-grabbed') === 'true'
        && capturedPointerId === ALBUM_POINTER_ID,
        'Moving the captured handle beyond the threshold must mark the target insertion side.');
    const nativeFallbackStart = new DragEvent('dragstart', {
        bubbles: true,
        cancelable: true,
    });
    dragHandle.dispatchEvent(nativeFallbackStart);
    check(nativeFallbackStart.defaultPrevented,
        'A pointer-origin drag must suppress the native fallback to prevent duplicate writes.');
    const postsBeforePointerCommit = calls.filter(
        call => call.method === 'POST',
    ).length;
    check(postsBeforePointerCommit === writesBeforePointer,
        'Moving the handle must not submit before pointer release.');
    dispatchAlbumPointer(dragTarget, 'pointerup', dragEnd);
    await waitForIdle();
    root = document.querySelector('[data-admin-music]');
    const postsAfterPointerCommit = calls.filter(
        call => call.method === 'POST',
    ).length;
    const pointerCommitState = {
        postsBefore: postsBeforePointerCommit,
        postsAfter: postsAfterPointerCommit,
        lastWrite: writeBodies.at(-1),
        capturedPointerId,
        releasedPointerId,
        restoredPosition: reorderPosition?.value,
        albumsHidden: root.querySelector('#admin-music-tab-albums')?.hidden,
        currentUrl: location.href,
        expectedUrl: pageBeforeAlbumCreate,
    };
    check(postsAfterPointerCommit === postsBeforePointerCommit + 1
        && writeBodies.at(-1)?.action === 'album_reorder_song'
        && writeBodies.at(-1)?.songId === '101'
        && writeBodies.at(-1)?.albumId === '8'
        && writeBodies.at(-1)?.position === '1'
        && writeBodies.at(-1)?.returnTab === 'admin-music-tab-albums'
        && capturedPointerId === null
        && releasedPointerId === ALBUM_POINTER_ID
        && reorderPosition?.value === '0'
        && root.querySelector('#admin-music-tab-albums')?.hidden === false
        && location.href === pageBeforeAlbumCreate,
        'Dropping after the second member must submit absolute position 1 '
            + 'through the canonical reorder coordinator and preserve the '
            + 'Albums tab: ' + JSON.stringify(pointerCommitState));
    root.querySelector(
        '.admin-music-tabs [data-admin-tab-target="admin-music-tab-songs"]',
    ).click();
    expectedPageUrl = location.href;

    root = document.querySelector('[data-admin-music]');
    let liveSearch = root.querySelector('form.admin-music-search input[name="q"]');
    liveSearch.focus();
    liveSearch.value = 'Midnight';
    liveSearch.setSelectionRange(3, 3);
    liveSearch.dispatchEvent(new Event('input', {bubbles: true}));
    await waitForBrowserState(
        () => searchRequests.some(request => request.query === 'Midnight'),
        'The debounced first live-search request did not start.',
    );
    const olderSearch = searchRequests.find(request => request.query === 'Midnight');
    check(olderSearch.signal instanceof AbortSignal
        && olderSearch.signal.aborted === false,
        'The active search request must remain usable until a newer input arrives.');

    liveSearch = root.querySelector('form.admin-music-search input[name="q"]');
    liveSearch.value = 'Quiet';
    liveSearch.setSelectionRange(2, 2);
    liveSearch.dispatchEvent(new Event('input', {bubbles: true}));
    check(olderSearch.signal.aborted === true
        && !searchRequests.some(request => request.query === 'Quiet'),
        'New typing must invalidate the previous search immediately, before debounce.');
    await waitForBrowserState(
        () => searchRequests.some(request => request.query === 'Quiet'),
        'The latest debounced live-search request did not start.',
    );
    const latestSearch = searchRequests.find(request => request.query === 'Quiet');
    const latestSearchUrl = new URL(latestSearch.url);
    check(latestSearchUrl.searchParams.get('q') === 'Quiet'
        && latestSearchUrl.searchParams.get('tab') === 'songs'
        && latestSearchUrl.searchParams.get('album_id') === '8'
        && latestSearchUrl.searchParams.get('offset') === '0'
        && latestSearchUrl.searchParams.get('sort') === 'files'
        && latestSearchUrl.searchParams.get('direction') === 'desc',
        'Live search must retain album and sort state while resetting its offset.');
    check(latestSearchUrl.hash === '#admin-music-tab-songs',
        'Live search must retain the selected tab hash in canonical browser history.');
    check(latestSearchUrl.searchParams.get('fragment') === '1',
        'Live search must request the server-owned fragment transport.');
    const latestCanonicalUrl = new URL(latestSearchUrl.toString());
    latestCanonicalUrl.searchParams.delete('fragment');
    await waitForBrowserState(
        () => document.querySelectorAll('[data-admin-music] tbody tr').length === 1
            && document.querySelector('[data-admin-music] tbody tr')
                ?.textContent.includes('Quiet Morning'),
        'The latest PHP-rendered search fragment did not replace catalog rows.',
    );
    liveSearch = document.querySelector(
        '[data-admin-music] form.admin-music-search input[name="q"]',
    );
    check(liveSearch.value === 'Quiet'
        && document.activeElement === liveSearch
        && liveSearch.selectionStart === 2
        && liveSearch.selectionEnd === 2
        && location.href === latestCanonicalUrl.toString(),
        'Search replacement must retain query focus/selection and update history only for the latest result.');
    const searchedRoot = document.querySelector('[data-admin-music]');
    check(searchedRoot.querySelector('.admin-music-tabs') === sharedTabsNavigation
        && searchedRoot.querySelector('#admin-music-tab-songs') === sharedSongsPanel
        && searchedRoot.querySelector('#admin-music-tab-albums') === sharedAlbumsPanel,
        'Live search must preserve the shared tab nodes so their existing listeners remain bounded.');

    const staleResponse = new Response(freshMusicMarkup,
        {headers: {'Content-Type': 'text/html'}});
    const readStaleBody = staleResponse.text.bind(staleResponse);
    staleResponse.text = async () => {
        const bodyText = await readStaleBody();
        olderSearchBodyRead = true;
        return bodyText;
    };
    check(typeof releaseOlderSearch === 'function',
        'The earlier search response was not held for the out-of-order check.');
    releaseOlderSearch(staleResponse);
    await waitForBrowserState(
        () => olderSearchBodyRead,
        'The deliberately delayed old response body did not finish.');
    await new Promise(/** Allow the old response continuation to reach its generation guard. @param {function():void} resolve Continue after one passive browser turn. @returns {void} Schedules one timer. */ resolve => setTimeout(resolve, ACTION_POLL_MS));
    check(document.querySelectorAll('[data-admin-music] tbody tr').length === 1
        && document.querySelector('[data-admin-music] tbody tr')
            ?.textContent.includes('Quiet Morning')
        && location.href === latestCanonicalUrl.toString(),
        'An older response body must not overwrite newer rows or browser history.');
    expectedPageUrl = location.href;

    liveSearch = document.querySelector(
        '[data-admin-music] form.admin-music-search input[name="q"]',
    );
    liveSearch.value = 'Broken';
    liveSearch.dispatchEvent(new Event('input', {bubbles: true}));
    await waitForBrowserState(
        () => document.querySelector('[data-admin-music-search-status]') !== null,
        'A failed current search did not report its read error.',
    );
    check(document.querySelector('[data-admin-music-search-status]')?.textContent
            === 'Search could not refresh. Try again.'
        && document.querySelector('[data-admin-music] form.admin-music-search input[name="q"]')
            ?.value === 'Broken'
        && location.href === latestCanonicalUrl.toString(),
        'A failed search must retain the typed query and leave history at the last successful result.');

    liveSearch = document.querySelector(
        '[data-admin-music] form.admin-music-search input[name="q"]',
    );
    liveSearch.value = 'TitleRace';
    liveSearch.dispatchEvent(new Event('input', {bubbles: true}));
    await waitForBrowserState(
        () => searchRequests.some(request => request.query === 'TitleRace'),
        'The in-flight editor race search did not start.',
    );
    const editorRaceSearch = searchRequests.find(
        request => request.query === 'TitleRace',
    );
    const editorRaceRoot = document.querySelector('[data-admin-music]');
    const titleButtonDuringSearch = editorRaceRoot.querySelector(
        '[data-admin-music-title-edit]',
    );
    titleButtonDuringSearch.click();
    const editorDuringSearch = editorRaceRoot.querySelector(
        'textarea[name="title"]',
    );
    check(editorRaceSearch.signal.aborted
        && editorDuringSearch.closest('details')?.open === true
        && document.activeElement === editorDuringSearch
        && typeof releaseTitleRace === 'function',
        'Opening a title editor must cancel an in-flight search before it can replace the focused form.');
    releaseTitleRace(new Response(freshMusicMarkup,
        {headers: {'Content-Type': 'text/html'}}));
    await new Promise(/** Let an abort-ignoring mocked response reach the stale-generation guard. @param {function():void} resolve Resume after one passive browser task. @returns {void} Schedules one timer. */ resolve => setTimeout(resolve, ACTION_POLL_MS));
    check(document.querySelector('[data-admin-music]') === editorRaceRoot
        && editorDuringSearch.isConnected
        && editorDuringSearch.closest('details')?.open === true
        && document.activeElement === editorDuringSearch,
        'A stale search body must not replace an active title editor or steal focus.');
    const editorEscape = new KeyboardEvent('keydown', {
        key: 'Escape',
        bubbles: true,
        cancelable: true,
    });
    editorDuringSearch.dispatchEvent(editorEscape);
    check(editorEscape.defaultPrevented === false
        && editorDuringSearch.closest('details')?.open === false,
        'Escape must close the title disclosure while preserving native key behavior.');
    liveSearch = editorRaceRoot.querySelector(
        'form.admin-music-search input[name="q"]',
    );
    liveSearch.value = 'TagRace';
    liveSearch.dispatchEvent(new Event('input', {bubbles: true}));
    await waitForBrowserState(
        () => searchRequests.some(request => request.query === 'TagRace'),
        'The in-flight tag-editor race search did not start.',
    );
    const tagRaceSearch = searchRequests.find(
        request => request.query === 'TagRace',
    );
    const tagInputDuringSearch = editorRaceRoot.querySelector(
        'input[name="tags"]',
    );
    const tagDisclosureDuringSearch = tagInputDuringSearch.closest('details');
    tagDisclosureDuringSearch.querySelector(':scope > summary').click();
    tagInputDuringSearch.value = 'unsaved tag draft';
    tagInputDuringSearch.focus();
    check(tagRaceSearch.signal.aborted
        && tagDisclosureDuringSearch.open
        && document.activeElement === tagInputDuringSearch
        && typeof releaseTagRace === 'function',
        'Opening and editing tags must cancel a search before its fragment can replace the active form.');
    releaseTagRace(new Response(freshMusicMarkup,
        {headers: {'Content-Type': 'text/html'}}));
    await new Promise(/** Let the abort-ignoring mocked tag response reach its stale-generation guard. @param {function():void} resolve Resume after one passive browser task. @returns {void} Schedules one timer. */ resolve => setTimeout(resolve, ACTION_POLL_MS));
    check(document.querySelector('[data-admin-music]') === editorRaceRoot
        && tagInputDuringSearch.isConnected
        && tagInputDuringSearch.value === 'unsaved tag draft'
        && tagDisclosureDuringSearch.open
        && document.activeElement === tagInputDuringSearch,
        'A stale search body must preserve an active tag draft and its focus.');
    const searchedRootBeforeTabSwitch = document.querySelector('[data-admin-music]');
    searchedRootBeforeTabSwitch.querySelector(
        '.admin-music-tabs [data-admin-tab-target="admin-music-tab-albums"]',
    ).click();
    check(searchedRootBeforeTabSwitch.querySelector('#admin-music-tab-albums')
            ?.hidden === false
        && searchedRootBeforeTabSwitch.querySelectorAll(
            'form input[name="return_tab"]',
        ).length > 0
        && Array.from(searchedRootBeforeTabSwitch.querySelectorAll(
            'form input[name="return_tab"]',
        )).every(input => input.value === 'admin-music-tab-albums'),
        'The shared tab group must keep working after a live fragment refresh and update all new return fields.');
    searchedRootBeforeTabSwitch.querySelector(
        '.admin-music-tabs [data-admin-tab-target="admin-music-tab-songs"]',
    ).click();
    const postSearchUrl = location.href;

    mode = 'unsynchronized';
    root = editorRaceRoot;
    root.querySelector('input[name="show_extensions"]').form.requestSubmit(
        root.querySelector('input[name="show_extensions"]').form.querySelector('button')
    );
    await waitForIdle();
    root = document.querySelector('[data-admin-music]');
    check(root.dataset.stale === '1'
        && root.querySelector('[role="alert"]')?.textContent.includes('could not refresh')
        && Array.from(root.querySelectorAll('button, input, select, textarea'))
            .filter(/** Keep only controls owned by a manager write form. @param {HTMLButtonElement|HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} control Rendered manager control. @returns {boolean} True for controls inside a manager form. */ control => control.form?.matches('[data-admin-music-form]'))
            .every(/** Require every owned control to be disabled. @param {HTMLButtonElement|HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} control Rendered manager control. @returns {boolean} True when the stale lock is active. */ control => control.disabled),
        'Failed synchronization did not lock writes and show the reload warning.');
    check(location.href === postSearchUrl,
        'Music Manager mutation changed the browser URL.');
    document.getElementById('results').textContent = 'BROWSER PASS Admin Music Manager layout, disclosure, mutation, refresh, rejection, and stale lock';
} catch (error) {
    document.getElementById('results').textContent = 'BROWSER FAIL ' + (error.stack || String(error));
}
</script></body></html>`;

const server = createServer(/** Serve only the local fixture document and production assets. @param {IncomingMessage} request Loopback browser request. @param {ServerResponse} response Response owned by this test server. @returns {Promise<void>} Completes the bounded local response. */ async (request, response) => {
    try {
        const url = new URL(request.url || '/', 'http://127.0.0.1');
        if (url.pathname === '/music-search-render') {
            const renderedMarkup = execFileSync(
                phpBinary,
                [fixturePath, url.searchParams.toString()],
                {
                    encoding: 'utf8',
                    windowsHide: true,
                    timeout: PHP_RENDER_TIMEOUT_MS,
                },
            );
            response.writeHead(200, {
                'Content-Type': 'text/html; charset=utf-8',
            }).end(renderedMarkup);
            return;
        }
        if (url.pathname === '/') {
            response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'}).end(page);
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

await new Promise(/** Start the loopback-only fixture server. @param {function():void} resolve Continue after the server binds. @returns {void} Completes the listen barrier. */ resolve => server.listen(0, '127.0.0.1', resolve));
try {
    const url = 'http://127.0.0.1:' + server.address().port + '/';
    const evidenceDirectory = path.join(
        root,
        'cache',
        'test-audit',
        'admin-music-browser-' + process.pid,
    );
    await mkdir(evidenceDirectory, {recursive: true});
    const result = await runHeadlessBrowserFixture(
        executable,
        url,
        'admin-music-browser-',
        {
            viewport: {width: 1280, height: 900},
            timeoutMs: BROWSER_TIMEOUT_MS,
            interact: /** Capture both layouts and exercise native album disclosures before releasing the page sequence. @param {{evaluate:function(string):Promise<null|boolean|number|string|Array<*>|Record<string,*>>,captureScreenshot:function():Promise<string>,setViewport:function({width:number,height:number}):Promise<void>,key:function('keyDown'|'keyUp',string):Promise<void>,mouse:function('mouseMoved'|'mousePressed'|'mouseReleased',number,number,number,number=):Promise<void>}} tools Owned loopback browser controls. @returns {Promise<void>} Writes PNG evidence, verifies native disclosure input and resumes the page. */ async ({evaluate, captureScreenshot, setViewport, key, mouse}) => {
                const readyDeadline = Date.now() + VISUAL_READY_TIMEOUT_MS;
                while (Date.now() < readyDeadline
                    && await evaluate('window.__adminMusicDesktopReady === true') !== true) {
                    const earlyResult = await evaluate(
                        'document.getElementById("results")?.textContent || ""',
                    );
                    assert.ok(!String(earlyResult).startsWith('BROWSER FAIL'),
                        String(earlyResult));
                    await new Promise(/** Resume the bounded desktop readiness poll. @param {function():void} resolve Continue polling after a short yield. @returns {void} Schedules one timer. */ resolve => setTimeout(resolve, ACTION_POLL_MS));
                }
                assert.equal(
                    await evaluate('window.__adminMusicDesktopReady === true'),
                    true,
                    'The production page did not reach its initial desktop render.',
                );
                const desktopPng = Buffer.from(await captureScreenshot(), 'base64');
                assert.equal(desktopPng.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
                assert.equal(desktopPng.readUInt32BE(16), 1280);
                assert.ok(desktopPng.readUInt32BE(20) >= 900);
                await writeFile(path.join(evidenceDirectory, 'desktop.png'), desktopPng);

                const mergeOverlayState = await evaluate(`(() => {
                    const root = document.querySelector('[data-admin-music]');
                    const details = root?.querySelector('details.admin-music-merge');
                    const summary = details?.querySelector(':scope > summary');
                    const popup = details?.querySelector(':scope > form');
                    const catalog = root?.querySelector('.admin-music-catalog');
                    const albums = root?.querySelector('.admin-music-albums');
                    const motionStyle = document.documentElement.style;
                    window.__adminMusicPreviousMotion = motionStyle
                        .getPropertyValue('--admin-side-panel-motion-duration');
                    motionStyle.setProperty('--admin-side-panel-motion-duration', '0ms');
                    summary?.click();
                    const popupBounds = popup?.getBoundingClientRect();
                    const catalogBounds = catalog?.getBoundingClientRect();
                    const albumBounds = albums?.getBoundingClientRect();
                    const point = popupBounds ? {
                        x: popupBounds.left + popupBounds.width / 2,
                        y: popupBounds.top + popupBounds.height / 2,
                    } : null;
                    const hit = point
                        ? document.elementFromPoint(point.x, point.y) : null;
                    return {
                        open: details?.open === true,
                        visible: Boolean(popup?.getClientRects().length),
                        insideCatalog: Boolean(popupBounds && catalogBounds
                            && popupBounds.left >= catalogBounds.left
                            && popupBounds.right <= catalogBounds.right),
                        overlapsAlbum: Boolean(popupBounds && albumBounds
                            && popupBounds.right > albumBounds.left
                            && popupBounds.left < albumBounds.right
                            && popupBounds.bottom > albumBounds.top
                            && popupBounds.top < albumBounds.bottom),
                        hitBelongsToPopup: Boolean(hit && popup?.contains(hit)),
                        withinViewport: Boolean(popupBounds
                            && popupBounds.left >= 0
                            && popupBounds.right <= innerWidth
                            && popupBounds.top >= 0
                            && popupBounds.bottom <= innerHeight),
                    };
                })()`);
                assert.deepEqual(mergeOverlayState, {
                    open: true,
                    visible: true,
                    insideCatalog: true,
                    overlapsAlbum: false,
                    hitBelongsToPopup: true,
                    withinViewport: true,
                }, 'Desktop merge controls must be visible and hit-testable inside their catalog, above the album panel.');
                const mergeOverlayPng = Buffer.from(
                    await captureScreenshot(),
                    'base64',
                );
                assert.equal(mergeOverlayPng.readUInt32BE(16), 1280);
                await writeFile(
                    path.join(evidenceDirectory, 'desktop-merge-open.png'),
                    mergeOverlayPng,
                );
                await evaluate(`(() => {
                    const summary = document.querySelector(
                        'details.admin-music-merge > summary',
                    );
                    summary?.click();
                    const motionStyle = document.documentElement.style;
                    const previous = window.__adminMusicPreviousMotion || '';
                    if (previous === '') {
                        motionStyle.removeProperty('--admin-side-panel-motion-duration');
                    } else {
                        motionStyle.setProperty('--admin-side-panel-motion-duration', previous);
                    }
                    delete window.__adminMusicPreviousMotion;
                    return true;
                })()`);

                await setViewport({width: 390, height: 844});
                const mobilePng = Buffer.from(await captureScreenshot(), 'base64');
                assert.equal(mobilePng.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
                assert.equal(mobilePng.readUInt32BE(16), 390);
                assert.ok(mobilePng.readUInt32BE(20) >= 844);
                await writeFile(path.join(evidenceDirectory, 'mobile.png'), mobilePng);

                await evaluate(`(() => {
                    document.querySelector(
                        '.admin-music-tabs [data-admin-tab-target="admin-music-tab-albums"]',
                    )?.click();
                    return true;
                })()`);

                const contextOpenState = await evaluate(`(() => {
                    const details = document.querySelector(
                        '.admin-music-album-detail > header.admin-music-album-heading'
                        + ' > details.admin-music-album-options',
                    );
                    details?.querySelector(':scope > summary')?.click();
                    return {
                        open: details?.open === true,
                        noOverflow: document.documentElement.scrollWidth <= innerWidth,
                    };
                })()`);
                assert.deepEqual(contextOpenState, {open: true, noOverflow: true},
                    'The mobile album gear must open without introducing horizontal overflow.');
                const contextOpenPng = Buffer.from(await captureScreenshot(), 'base64');
                assert.equal(contextOpenPng.readUInt32BE(16), 390);
                await writeFile(
                    path.join(evidenceDirectory, 'mobile-album-context-open.png'),
                    contextOpenPng,
                );
                assert.equal(await evaluate(`(() => {
                    const details = document.querySelector(
                        '.admin-music-album-detail > header.admin-music-album-heading'
                        + ' > details.admin-music-album-options',
                    );
                    details?.querySelector(':scope > summary')?.click();
                    return details?.open === false;
                })()`), true, 'The screenshot-only gear preview must return to its collapsed state.');

                await evaluate(`(() => {
                    document.querySelector(
                        '.admin-music-tabs [data-admin-tab-target="admin-music-tab-songs"]',
                    )?.click();
                    return true;
                })()`);

                const lastLocationState = await evaluate(`(() => {
                    const root = document.querySelector('[data-admin-music]');
                    const details = root?.querySelector(
                        'tbody tr:last-child td:nth-child(3) details',
                    );
                    const summary = details?.querySelector(':scope > summary');
                    const popup = details?.querySelector(':scope > ul');
                    const item = popup?.querySelector('li');
                    item?.scrollIntoView({block: 'center'});
                    window.__adminMusicPreviousMotion = document.documentElement.style
                        .getPropertyValue('--admin-side-panel-motion-duration');
                    document.documentElement.style.setProperty(
                        '--admin-side-panel-motion-duration', '0ms',
                    );
                    summary?.click();
                    const bounds = popup?.getBoundingClientRect();
                    const itemBounds = item?.getBoundingClientRect();
                    const hit = itemBounds
                        ? document.elementFromPoint(
                            itemBounds.left + itemBounds.width / 2,
                            itemBounds.top + itemBounds.height / 2,
                        )
                        : null;
                    return {
                        open: details?.open === true,
                        visible: Boolean(popup?.getClientRects().length),
                        contentVisible: Boolean(itemBounds && itemBounds.width > 0
                            && itemBounds.height > 0),
                        withinViewport: Boolean(bounds
                            && bounds.left >= 0
                            && bounds.right <= innerWidth
                            && bounds.top >= 0
                            && bounds.bottom <= innerHeight),
                        contentHitBelongsToPopup: Boolean(hit && popup?.contains(hit)),
                        noOverflow: document.documentElement.scrollWidth <= innerWidth,
                        bounds: bounds ? {
                            left: bounds.left,
                            top: bounds.top,
                            right: bounds.right,
                            bottom: bounds.bottom,
                            width: bounds.width,
                            height: bounds.height,
                        } : null,
                        viewport: {width: innerWidth, height: innerHeight},
                    };
                })()`);
                assert.ok(lastLocationState.open
                    && lastLocationState.visible
                    && lastLocationState.contentVisible
                    && lastLocationState.withinViewport
                    && lastLocationState.contentHitBelongsToPopup
                    && lastLocationState.noOverflow,
                'The last-row Locations popup must remain visible, unclipped, and hit-testable on mobile; actual geometry: '
                    + JSON.stringify(lastLocationState));
                const lastLocationsPng = Buffer.from(
                    await captureScreenshot(),
                    'base64',
                );
                assert.equal(lastLocationsPng.readUInt32BE(16), 390);
                await writeFile(
                    path.join(evidenceDirectory, 'mobile-last-locations-open.png'),
                    lastLocationsPng,
                );
                await evaluate(`(() => {
                    document.querySelector(
                        'tbody tr:last-child td:nth-child(3) details > summary',
                    )?.click();
                    const motionStyle = document.documentElement.style;
                    const previous = window.__adminMusicPreviousMotion || '';
                    if (previous === '') {
                        motionStyle.removeProperty('--admin-side-panel-motion-duration');
                    } else {
                        motionStyle.setProperty('--admin-side-panel-motion-duration', previous);
                    }
                    delete window.__adminMusicPreviousMotion;
                    window.scrollTo(0, 0);
                    return true;
                })()`);

                const initialTreeState = await evaluate(`(() => {
                    document.querySelector(
                        '.admin-music-tabs [data-admin-tab-target="admin-music-tab-albums"]',
                    )?.click();
                    const branches = Array.from(document.querySelectorAll(
                        '.admin-music-album-tree details.admin-music-album-branch',
                    ));
                    const branchNamed = name => branches.find(branch =>
                        branch.querySelector(':scope > summary a')?.textContent.trim() === name,
                    );
                    const selectedParent = branchNamed('Road Trips');
                    const unrelatedParent = branchNamed('Bonus Sessions');
                    const selectedChild = Array.from(document.querySelectorAll(
                        '.admin-music-album-tree a',
                    )).find(link => link.textContent.trim() === 'Night Drives');
                    const summary = unrelatedParent?.querySelector(':scope > summary');
                    summary?.focus();
                    return {
                        selectedParentOpen: selectedParent?.open === true,
                        unrelatedParentOpen: unrelatedParent?.open === true,
                        selectedChildVisible: selectedChild?.getBoundingClientRect().width > 0,
                        unrelatedSummaryFocused: document.activeElement === summary,
                    };
                })()`);
                assert.deepEqual(initialTreeState, {
                    selectedParentOpen: true,
                    unrelatedParentOpen: false,
                    selectedChildVisible: true,
                    unrelatedSummaryFocused: true,
                }, 'Selected album ancestry must be visible while an unrelated branch stays collapsed.');

                await key('keyDown', 'Space');
                await key('keyUp', 'Space');
                assert.equal(
                    await evaluate(`document.querySelector(
                        '.admin-music-album-tree a[href*="album_id=9"]',
                    )?.closest('details')?.open`),
                    true,
                    'Space must natively expand the focused album branch.',
                );
                await key('keyDown', 'Space');
                await key('keyUp', 'Space');
                assert.equal(
                    await evaluate(`document.querySelector(
                        '.admin-music-album-tree a[href*="album_id=9"]',
                    )?.closest('details')?.open`),
                    false,
                    'Space must natively collapse the focused album branch.',
                );
                const clickPoint = await evaluate(`(() => {
                    const branch = Array.from(document.querySelectorAll(
                        '.admin-music-album-tree details.admin-music-album-branch',
                    )).find(candidate => candidate.querySelector(
                        ':scope > summary a[href*="album_id=9"]',
                    ));
                    const target = branch?.querySelector(':scope > summary span');
                    target?.scrollIntoView({block: 'center'});
                    const bounds = target?.getBoundingClientRect();
                    return bounds ? {
                        x: bounds.x + bounds.width / 2,
                        y: bounds.y + bounds.height / 2,
                    } : null;
                })()`);
                assert.ok(clickPoint && clickPoint.x > 0 && clickPoint.y > 0,
                    'Unrelated album disclosure has no visible native click target.');
                await mouse('mouseMoved', clickPoint.x, clickPoint.y, 0);
                await mouse('mousePressed', clickPoint.x, clickPoint.y, 1, 1);
                await mouse('mouseReleased', clickPoint.x, clickPoint.y, 0, 1);
                assert.equal(
                    await evaluate(`document.querySelector(
                        '.admin-music-album-tree a[href*="album_id=9"]',
                    )?.closest('details')?.open`),
                    true,
                    'A native click must expand the unrelated album branch.');
                assert.equal(
                    await evaluate('document.documentElement.scrollWidth <= innerWidth'),
                    true,
                    'Expanding the album tree must not overflow the 390px mobile viewport.',
                );
                const expandedMobilePng = Buffer.from(
                    await captureScreenshot(),
                    'base64',
                );
                assert.equal(
                    expandedMobilePng.readUInt32BE(16),
                    390,
                    'Expanded album tree evidence must retain the mobile viewport width.',
                );
                await writeFile(
                    path.join(evidenceDirectory, 'mobile-album-tree-expanded.png'),
                    expandedMobilePng,
                );
                await evaluate('window.__adminMusicContinueAfterScreenshots?.()');
            },
        },
    );
    console.log(result.result);
    assert.equal(result.exitCode, 0);
    assert.ok(result.result.startsWith('BROWSER PASS'),
        'Admin Music Manager browser fixture must complete successfully.');
} finally {
    server.close();
}
