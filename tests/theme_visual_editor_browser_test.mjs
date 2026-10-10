/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_visual_editor_browser_test.mjs
 * Module Type: Browser Regression Test
 * Purpose: Verify protected preview navigation and isolated visual editing against authentic Theme shell behavior.
 * Responsibilities: Exercise managed CSS, width and background drafts, pending File lifecycle, native resizing, undo, Escape rollback, and exact cancellation.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createServer} from 'node:http';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';

/** Absolute checkout root used only to read two allowlisted first-party preview assets. */
const repositoryRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/**
 * Render the allowlisted public-page stand-in used by the isolated browser fixture.
 * @param {import('node:http').IncomingMessage} request Loopback-only preview request.
 * @param {import('node:http').ServerResponse} response Fixture response writer.
 * @returns {void} Emits an inert public page with server-selected audience visibility.
 */
function serveVisualPreview(request, response) {
    const url = new URL(request.url || '/', 'http://127.0.0.1');
    const appPath = url.pathname.slice('/app/'.length);
    const allowed = (appPath === 'index.php' && ['home', 'gallery'].includes(url.searchParams.get('page')))
        || (appPath === '' && !url.searchParams.has('page'))
        || (appPath.startsWith('gallery/') && !url.searchParams.has('page'))
        || (/^galleries\/[1-9][0-9]*\/$/.test(appPath) && !url.searchParams.has('page'));
    if (request.method !== 'GET' || !allowed || url.searchParams.get('preview') !== 'visual') {
        response.writeHead(404).end('Not found');
        return;
    }
    const anonymous = url.searchParams.get('view_as') === 'anonymous';
    if (anonymous && appPath === 'gallery/private/') {
        response.writeHead(302, {Location: '/app/index.php?page=home&preview=visual&view_as=anonymous&visual_notice=anonymous_fallback'}).end();
        return;
    }
    const refusal = appPath === 'gallery/refusal-import/' ? 'import'
        : appPath === 'gallery/refusal-inspection/' ? 'inspection'
            : appPath === 'gallery/refusal-unknown/' ? 'future_reason' : '';
    if (refusal) {
        const message = refusal === 'import'
            ? 'The visual editor cannot safely preview CSS with @import. Use Manual CSS, or remove the import rules before opening this preview.'
            : refusal === 'inspection'
                ? 'The visual editor cannot safely inspect the installed stylesheets. Check the saved CSS, or continue in Manual CSS.'
                : 'Preview inspection is unavailable.';
        response.writeHead(409, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'private, no-store'});
        response.end('<!doctype html><html><body><div data-visual-preview-blocked="' + refusal + '">' + message + '</div></body></html>');
        return;
    }
    const isGallery = appPath.startsWith('gallery/');
    response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'private, no-store'});
    response.end('<!doctype html><html><head><meta charset="utf-8"><title>Fixture ' + (isGallery ? 'gallery' : 'home') + '</title>'
        + '<style>:root{--page-width-default:1120px;--page-width-wide:1440px;--page-width-custom:1600px}body{font:16px sans-serif;background:#fff;margin:0}.draft-target{color:#000}.public-page .site-header,.public-page .site-main,.public-page .site-footer{width:min(var(--page-width-default,1120px),calc(100% - 2rem));max-width:none;margin-left:auto;margin-right:auto}.public-page.page-width-wide .site-header,.public-page.page-width-wide .site-main,.public-page.page-width-wide .site-footer{width:min(var(--page-width-wide,1440px),calc(100% - 2rem));max-width:none}.public-page.page-width-custom .site-header,.public-page.page-width-custom .site-main,.public-page.page-width-custom .site-footer{width:min(var(--page-width-custom,1440px),calc(100% - 2rem));max-width:none}.public-page.page-width-full .site-header,.public-page.page-width-full .site-main,.public-page.page-width-full .site-footer{width:calc(100% - clamp(1rem,3vw,3rem));max-width:none}.theme-background-shell{position:fixed;inset:0;pointer-events:none;z-index:0}.theme-background-base,.theme-background-image{position:absolute;inset:0}.theme-background-base{background:#fff}.theme-background-image{background-image:linear-gradient(135deg,#f00,#00f);background-size:cover;background-position:center center;background-repeat:no-repeat;opacity:.65}.public-page> *:not(.theme-background-shell){position:relative;z-index:1}.public-page .hero{position:relative;isolation:isolate;background-color:rgb(245,245,245);color:#111;backdrop-filter:blur(10px) saturate(1.06);-webkit-backdrop-filter:blur(10px) saturate(1.06)}.public-page .hero::before{content:"";position:absolute;inset:0;z-index:0;background-image:linear-gradient(180deg,rgba(255,255,255,.1),rgba(255,255,255,.22));pointer-events:none}.public-page .hero>*{position:relative;z-index:1}.public-page .site-header{backdrop-filter:blur(12px) saturate(1.08);-webkit-backdrop-filter:blur(12px) saturate(1.08)}</style></head>'
        + '<body class="public-page page-width-wide" data-audience="' + (anonymous ? 'anonymous' : 'signed-in') + '">'
        + '<div class="theme-background-shell" aria-hidden="true"><div class="theme-background-base"></div><div class="theme-background-image" data-theme-background-visual-editor-target="' + (isGallery ? 'none' : 'theme') + '" data-theme-background-visible-owner="' + (isGallery ? 'gallery_override' : 'theme_image') + '" data-theme-background-visible-mode="' + (isGallery ? 'existing' : 'theme_image') + '"></div></div>'
        + '<header class="site-header">Public header</header><main class="site-main"><section id="hero" class="hero"><h1 id="hero-title">Theme hero</h1><p>Hero content stays opaque</p></section>'
        + '<h1>Fixture ' + (isGallery ? 'gallery' : 'home') + '</h1><p id="draft-target" class="draft-target">Public preview content</p>'
        + '<article id="fixture-gallery-card" class="gallery-card" data-gallery-id="17"><a id="gallery-link" href="/app/gallery/album/?preview=visual">A gallery link</a><button type="button" id="fixture-action-button">Action</button></article>'
        + '<a id="home-pagination-link" href="/app/galleries/2/?preview=visual">A home pagination link</a>'
        + '<a id="private-gallery-link" href="/app/gallery/private/?preview=visual">A gated gallery link</a>'
        + '<a id="refusal-import-link" href="/app/gallery/refusal-import/?preview=visual">Import refusal</a>'
        + '<a id="refusal-inspection-link" href="/app/gallery/refusal-inspection/?preview=visual">Inspection refusal</a>'
        + '<a id="refusal-unknown-link" href="/app/gallery/refusal-unknown/?preview=visual">Unknown refusal</a>'
        + '<a id="unsafe-link" href="https://example.invalid/gallery/album/">External link</a>'
        + '<a id="admin-link" href="/app/index.php?page=admin&amp;preview=visual">Admin link</a>'
        + '<a id="target-link" target="_blank" href="/app/gallery/album/?preview=visual">New-window link</a>'
        + '<a id="redirect-link" href="/app/gallery/redirect/?preview=visual">Redirecting link</a>'
        + '<a id="download-link" download href="/app/gallery/album/?preview=visual">Download link</a>'
        + '<p id="unlinked">Unlinked content</p><main id="empty-background"><span>Background target</span></main>'
        + '<form action="/mutate" method="post"><button type="submit">Mutation</button></form>'
        + '</main><footer class="site-footer">Public footer</footer></body></html>');
}

/**
 * Render a fault-injection page that keeps real Manual CSS and Theme controls bound.
 * @param {import('node:http').ServerResponse} response Owned loopback response writer.
 * @param {string} scenario Missing workspace/dependency name or a one-shot initialization exception.
 * @returns {void} Emits a browser contract using production bootstrap with no persistent submission.
 */
function serveVisualAvailabilityFixture(response, scenario) {
    const missing = ['workspace', 'draft', 'resize', 'import'].includes(scenario);
    const prefix = missing ? '/unavailable/' + scenario : '';
    response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
    response.end(`<!doctype html><html><head><meta charset="utf-8"></head><body>
<form data-theme-form><div data-theme-advanced-control data-theme-advanced-key="public_type_scale" data-theme-advanced-default="100">
<input type="number" min="80" max="120" value="100" data-theme-advanced-value><input type="range" min="80" max="120" value="100" data-theme-advanced-slider><button type="button" data-theme-advanced-reset>Reset</button></div></form>
<form id="css-form" data-css-override-form action="/mutate" method="post"></form>
<section data-css-override-editor data-visual-editor-preview-url="/app/index.php?page=home&amp;preview=visual" data-saved-label="Saved" data-unsaved-label="Unsaved">
<textarea form="css-form" data-css-override-text>saved CSS</textarea><input data-css-override-revision value="revision">
<textarea hidden data-css-override-saved-text>saved CSS</textarea><input data-css-override-saved-revision value="revision">
<button type="button" data-visual-editor-launch disabled>Visual editor</button>
<span hidden data-visual-editor-availability-labels data-preview-module-unavailable="Localized module unavailable" data-preview-initialization-failed="Localized initialization failed"></span>
<p data-visual-editor-availability data-visual-editor-reason="preview_not_initialized">Server fallback</p>
<input type="file" name="theme_background_file" form="css-form" data-visual-editor-background-file><input name="theme_background_operation" value="keep" form="css-form" data-visual-editor-background-operation>
<span hidden data-visual-editor-background-labels data-visual-editor-background-operation-replace-label="Replace" data-visual-editor-background-operation-remove-label="Remove" data-visual-editor-background-target-global-label="Global Theme" data-visual-editor-background-review-hint-label="Draft only"></span><p data-visual-editor-background-status></p>
<button type="button" data-visual-editor-background-keep>Keep</button><button type="button" data-visual-editor-background-remove>Remove</button>
<button type="submit" name="css_override_action" value="save" form="css-form">Save</button>
<button type="button" data-css-override-clear-draft>Clear</button><button type="button" data-css-override-undo-clear-draft hidden>Undo</button><p data-css-override-status></p><p data-css-override-message></p></section>
<pre id="results"></pre><script type="module">
import {setupThemeCssOverrideEditor,setupThemeAdvancedAppearance,setupOptionalThemeVisualEditor} from '${prefix}/public/assets/gallery-modules/theme-customization.js?v=20261010-visual-editor-draft-availability';
const root=document.querySelector('[data-css-override-editor]');
const text=root.querySelector('textarea');
const initialUrl=location.href;
const launch=root.querySelector('[data-visual-editor-launch]');
try {
 if(launch.hidden||!launch.disabled)throw new Error('Server fallback must be visible and disabled');
 if(${JSON.stringify(scenario)}==='initialization'){
  const originalQuery=root.querySelector.bind(root);
  root.querySelector=selector=>{
   if(selector==='[data-visual-editor-launch]'){root.querySelector=originalQuery;throw new Error('Injected DOM initialization failure');}
   return originalQuery(selector);
  };
 }
 setupThemeAdvancedAppearance(document.querySelector('[data-theme-form]'));
 setupThemeCssOverrideEditor();
 const backgroundFile=root.querySelector('[data-visual-editor-background-file]');
 const draftFile=new File(['pending bytes'],'pending.png',{type:'image/png'});
 const transfer=new DataTransfer();transfer.items.add(draftFile);backgroundFile.files=transfer.files;
 backgroundFile.dispatchEvent(new Event('change',{bubbles:true}));
 if(root.querySelector('[data-visual-editor-background-operation]').value!=='replace')throw new Error('Pending File must stage before optional loading completes');
 const first=setupOptionalThemeVisualEditor(root,text);
 const second=setupOptionalThemeVisualEditor(root,text);
 if(first!==second)throw new Error('Concurrent startup must share the same initialization promise');
 await first;
 const status=root.querySelector('[data-visual-editor-availability]');
 const reason=${JSON.stringify(missing ? 'preview_module_unavailable' : 'preview_initialization_failed')};
 if(launch.hidden||!launch.disabled||status.hidden||status.dataset.visualEditorReason!==reason
  ||status.textContent!==${JSON.stringify(missing ? 'Localized module unavailable' : 'Localized initialization failed')})throw new Error('Observed failure must have a bounded localized fallback');
 if(backgroundFile.files[0]!==draftFile||root.querySelector('[data-visual-editor-background-operation]').value!=='replace')throw new Error('Initialization failure must preserve pending File and operation');
 root.querySelector('[data-visual-editor-background-remove]').click();
 if(root.querySelector('[data-visual-editor-background-operation]').value!=='remove'||backgroundFile.files[0]!==draftFile)throw new Error('Fallback Remove stages without discarding File');
 root.querySelector('[data-visual-editor-background-keep]').click();
 if(backgroundFile.files.length!==0||root.querySelector('[data-visual-editor-background-operation]').value!=='keep')throw new Error('Fallback Keep clears only the pending background draft');
 text.value='unsaved draft';text.dispatchEvent(new Event('input',{bubbles:true}));
 if(root.querySelector('[data-css-override-status]').textContent!=='Unsaved')throw new Error('Manual CSS dirty-state binding must survive');
 root.querySelector('[data-css-override-clear-draft]').click();
 if(text.value!=='')throw new Error('Manual clear remains synchronous');
 root.querySelector('[data-css-override-undo-clear-draft]').click();
 if(text.value!=='unsaved draft')throw new Error('Manual Undo preserves the unsaved draft');
 const value=document.querySelector('[data-theme-advanced-value]');value.value='110';value.dispatchEvent(new Event('input'));
 if(document.querySelector('[data-theme-advanced-slider]').value!=='110')throw new Error('Other Theme controls remain bound');
 document.querySelector('[data-theme-advanced-reset]').click();
 if(value.value!=='100')throw new Error('Other Theme reset remains functional');
 window.fetch=async()=>({ok:true,status:200,json:async()=>({ok:true,message:'Saved',state:{text:text.value,revision:'saved-revision'}})});
 root.querySelector('[name="css_override_action"]').click();
 await new Promise(resolve=>setTimeout(resolve,0));
 if(!launch.disabled||status.dataset.visualEditorReason!==reason)throw new Error('CSS Save must preserve unavailable visual launch');
 setupThemeCssOverrideEditor();await setupOptionalThemeVisualEditor(root,text);
 launch.click();
 if(location.href!==initialUrl||document.querySelector('.theme-visual-editor-workspace')||text.value!=='unsaved draft')throw new Error('Unavailable editor must neither navigate, open nor change CSS');
 document.getElementById('results').textContent='BROWSER PASS visual availability '+${JSON.stringify(scenario)};
} catch(error){document.getElementById('results').textContent='BROWSER FAIL '+error.stack;}
</script></body></html>`);
}

/**
 * Serve only the editor fixture, its first-party modules and its protected home-page fixture.
 * @param {import('node:http').IncomingMessage} request Owned loopback browser request.
 * @param {import('node:http').ServerResponse} response Loopback response writer.
 * @returns {Promise<void>} Serves an explicit fixture or asset allowlist and rejects all other paths.
 */
async function serveFixture(request, response) {
    const url = new URL(request.url || '/', 'http://127.0.0.1');
    if (request.method === 'GET' && url.pathname === '/availability/') {
        serveVisualAvailabilityFixture(response, url.searchParams.get('case') || 'workspace');
        return;
    }

    if (request.method === 'GET' && url.pathname === '/app/gallery/redirect/') {
        response.writeHead(302, {Location: '/admin/'}).end();
        return;
    }
    if (url.pathname === '/app/index.php' || url.pathname === '/app/' || url.pathname.startsWith('/app/gallery/')
        || /^\/app\/galleries\/[1-9][0-9]*\/$/.test(url.pathname)) {
        serveVisualPreview(request, response);
        return;
    }
    const assetPaths = new Map([
        ['/public/assets/gallery-modules/theme-visual-editor.js', 'public/assets/gallery-modules/theme-visual-editor.js'],
        ['/public/assets/gallery-modules/theme-customization.js', 'public/assets/gallery-modules/theme-customization.js'],
        ['/public/assets/gallery-modules/theme-visual-editor-support.js', 'public/assets/gallery-modules/theme-visual-editor-support.js'],
        ['/public/assets/gallery-modules/theme-visual-css-draft.js', 'public/assets/gallery-modules/theme-visual-css-draft.js'],
        ['/public/assets/gallery-modules/theme-visual-css-resize.js', 'public/assets/gallery-modules/theme-visual-css-resize.js'],
        ['/public/assets/gallery-modules/theme-visual-css-import.js', 'public/assets/gallery-modules/theme-visual-css-import.js'],
        ['/public/assets/styles/admin-theme-visual-editor.css', 'public/assets/styles/admin-theme-visual-editor.css'],
    ]);
    const failure = url.pathname.match(/^\/unavailable\/(workspace|draft|resize|import)/);
    const assetPathname = failure ? url.pathname.slice(failure[0].length) : url.pathname;
    const missingModules = {workspace: 'theme-visual-editor.js', draft: 'theme-visual-css-draft.js', resize: 'theme-visual-css-resize.js', import: 'theme-visual-css-import.js'};
    if (failure && assetPathname.endsWith('/' + missingModules[failure[1]])) {
        response.writeHead(404).end('Injected missing optional dependency');
        return;
    }
    const assetPath = assetPaths.get(assetPathname);
    if (request.method === 'GET' && assetPath) {
        response.writeHead(200, {'Content-Type': assetPath.endsWith('.css') ? 'text/css; charset=utf-8' : 'text/javascript; charset=utf-8'});
        response.end(await readFile(path.join(repositoryRoot, assetPath)));
        return;
    }
    if (request.method !== 'GET' || url.pathname !== '/') {
        response.writeHead(404).end('Not found');
        return;
    }
    response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
    response.end(`<!doctype html><html><head><meta charset="utf-8"><title>Editor fixture</title></head>
<body><main><section data-css-override-editor data-visual-editor-preview-url="/app/index.php?page=home&amp;preview=visual"
data-visual-editor-title="Live Visual CSS Editor" data-visual-editor-home-label="Home" data-visual-editor-back-label="Back"
data-visual-editor-signed-in-label="Signed-in view" data-visual-editor-anonymous-label="Anonymous view"
data-visual-editor-desktop-label="Desktop" data-visual-editor-tablet-label="Tablet" data-visual-editor-mobile-label="Mobile"
data-visual-editor-exit-label="Exit preview" data-visual-editor-status-label="Draft loaded"
data-visual-editor-style-element-label="Style element" data-visual-editor-follow-link-label="Follow link"
data-visual-editor-follow-unavailable-label="This link cannot be opened in the preview." data-visual-editor-navigation-blocked-label="This destination is outside the protected preview routes."
data-visual-editor-background-label="Page background" data-visual-editor-matches-label="Matches"
data-visual-editor-scope-label="CSS scope" data-visual-editor-scope-site-label="All viewports"
data-visual-editor-scope-tablet-label="Tablet and smaller" data-visual-editor-scope-mobile-label="Mobile and smaller"
data-visual-editor-target-scope-label="Elements to style" data-visual-editor-all-matches-label="All matching elements"
data-visual-editor-only-this-label="Only this element" data-visual-editor-only-unavailable-label="Only this element (no stable sitewide identity)"
data-visual-editor-profile-text-label="Text" data-visual-editor-profile-media-label="Media" data-visual-editor-profile-card-label="Card"
data-visual-editor-profile-layout-label="Layout" data-visual-editor-profile-hero-label="Hero"
data-visual-editor-resize-top-label="Resize from top edge" data-visual-editor-resize-bottom-label="Resize from bottom edge"
data-visual-editor-resize-left-label="Resize from left edge" data-visual-editor-resize-right-label="Resize from right edge"
data-visual-editor-resize-top-left-label="Resize from top-left corner" data-visual-editor-resize-top-right-label="Resize from top-right corner"
data-visual-editor-resize-bottom-left-label="Resize from bottom-left corner" data-visual-editor-resize-bottom-right-label="Resize from bottom-right corner"
data-visual-editor-resizing-label="Previewing size change…"
data-visual-editor-specificity-warning-label="Effective values include Theme and browser styles. Pseudo-elements and interactive states can differ; a hero background edit also clears its Theme gradient overlay."
data-visual-editor-effective-value-label="Effective value" data-visual-editor-managed-value-label="Visual editor override"
data-visual-editor-value-unavailable-label="Unavailable" data-visual-editor-no-managed-override-label="No visual editor override"
data-visual-editor-css-value-label="CSS value" data-visual-editor-css-value-placeholder-label="Enter a supported CSS value"
data-visual-editor-choose-color-label="Choose color" data-visual-editor-opacity-label="Opacity"
data-visual-editor-reset-property-label="Reset property" data-visual-editor-invalid-value-label="Enter a supported value for this CSS property."
data-visual-editor-manual-only-label="CSS variables, functions and advanced rules must be edited in Manual CSS."
data-visual-editor-warning-malformed-label="The managed CSS block is malformed or was edited manually. It is unchanged; repair it in Manual CSS before using visual controls."
data-visual-editor-draft-warning-label="The visual change could not be applied; the existing CSS was kept."
data-visual-editor-independent-overlay-label="A separate pseudo-element overlay remains unchanged."
data-visual-editor-draft-updated-label="Visual draft properties" data-visual-editor-apply-exit-label="Apply to CSS editor & exit"
data-visual-editor-cancel-exit-label="Cancel & exit" data-visual-editor-undo-label="Undo" data-visual-editor-redo-label="Redo"
data-visual-editor-width-mode-label="Page width mode" data-visual-editor-width-default-label="Default" data-visual-editor-width-wide-label="Wide"
data-visual-editor-width-custom-label="Custom" data-visual-editor-width-full-label="Full width" data-visual-editor-width-value-label="Custom width"
data-visual-editor-width-numeric-value-label="Custom width in pixels" data-visual-editor-width-effective-label="Effective content width"
data-visual-editor-width-reset-label="Reset page width" data-visual-editor-background-choose-label="Choose background image" data-visual-editor-background-replace-label="Replace or choose image"
data-visual-editor-background-pending-label="Background image is a pending draft." data-visual-editor-background-opacity-label="Background opacity"
data-visual-editor-background-reset-opacity-label="Reset background opacity" data-visual-editor-background-clear-pending-label="Clear pending image"
data-visual-editor-background-file-invalid-label="Choose a JPEG, PNG, GIF or WebP image."
data-visual-editor-background-url="/app/theme-background.png" data-visual-editor-background-available="1" data-visual-editor-background-operation="keep" data-visual-editor-page-width-mode="wide" data-visual-editor-page-width-custom-px="1600">
<span hidden data-visual-editor-background-labels data-visual-editor-background-target-global-label="Global Theme"
data-visual-editor-background-current-label="Current global Theme image" data-visual-editor-background-empty-label="No global Theme background is saved."
data-visual-editor-background-keep-current-label="Keep current" data-visual-editor-background-replace-label="Replace or choose image"
data-visual-editor-background-remove-label="Remove background" data-visual-editor-background-operation-keep-label="Keep current"
data-visual-editor-background-operation-replace-label="Replace global Theme image with pending file"
data-visual-editor-background-operation-remove-label="Remove global Theme background"
data-visual-editor-background-review-hint-label="This background change is a draft. Save explicitly to apply it."
data-visual-editor-background-source-theme-image-label="Global Theme image" data-visual-editor-background-source-theme-gallery-fallback-label="Theme gallery fallback"
data-visual-editor-background-source-gallery-override-label="Gallery-specific background" data-visual-editor-background-mode-upload-label="Gallery cover"
data-visual-editor-background-mode-existing-label="Gallery-selected existing image" data-visual-editor-background-mode-collage-label="Gallery collage"
data-visual-editor-background-mode-none-label="No background image"
data-visual-editor-background-global-preview-unavailable-label="This route uses an independent gallery background. To edit the global Theme image, open Home."></span>
<span hidden data-visual-editor-background-fit-position-labels data-visual-editor-background-fit-label="Background fit"
data-visual-editor-background-fit-cover-label="Cover" data-visual-editor-background-fit-contain-label="Contain"
data-visual-editor-background-fit-reset-label="Reset background fit" data-visual-editor-background-position-label="Background position"
data-visual-editor-background-position-x-label="Horizontal position (%)" data-visual-editor-background-position-y-label="Vertical position (%)"
data-visual-editor-background-position-reset-label="Reset background position"
data-visual-editor-background-value-unsupported-label="The current background value is outside the visual controls’ supported format. Choose a supported value or edit it in Manual CSS."
data-visual-editor-background-override-conflict-label="A stronger CSS declaration prevents this visual override from taking effect."></span>
<span hidden data-visual-editor-lifecycle-labels data-visual-editor-reset-session-label="Reset visual session"
data-visual-editor-discard-session-label="Discard session" data-visual-editor-continue-editing-label="Continue editing"
data-visual-editor-exit-confirmation-label="Apply to CSS editor, discard the visual session, or continue editing."
data-visual-editor-collapse-controls-label="Collapse controls" data-visual-editor-expand-controls-label="Expand controls"
data-visual-editor-loading-label="Loading public preview…" data-visual-editor-selection-unavailable-label="The selected element is no longer available."
data-visual-editor-restore-saved-css-label="Restore saved CSS" data-visual-editor-clear-draft-label="Clear draft"
data-visual-editor-undo-clear-draft-label="Undo clear draft" data-visual-editor-restore-confirm-label="Restore saved CSS and clear the pending image? Unsaved changes will be discarded."
data-visual-editor-inspection-unavailable-label="The visual editor cannot safely inspect the installed stylesheets. Check the saved CSS, or continue in Manual CSS."></span>
<span hidden data-visual-editor-extension-labels data-visual-editor-profile-link-button-label="Links and buttons"
data-visual-editor-profile-header-label="Header" data-visual-editor-text-decoration-label="Text decoration"
data-visual-editor-backdrop-filter-label="Backdrop filter" data-visual-editor-webkit-backdrop-filter-label="WebKit backdrop filter"
data-visual-editor-anonymous-fallback-notice-label="This gallery is not available in Anonymous view. The preview moved to an accessible page."
data-visual-editor-width-precedence-hint-label="A CSS width draft takes precedence over the saved Appearance width. Reset removes the CSS override; saved Appearance settings remain unchanged."></span>
<form id="admin-theme-css-overrides-form" data-css-override-form action="/app/admin-theme-css-overrides"></form><textarea data-css-override-text name="css_override_text"></textarea>
<textarea hidden data-css-override-saved-text></textarea><input type="hidden" data-css-override-saved-revision value="saved-revision">
<input type="hidden" name="css_override_revision" data-css-override-revision value="draft-revision">
<input type="file" form="admin-theme-css-overrides-form" data-visual-editor-background-file><input type="hidden" data-visual-editor-background-revision value="fixture-revision">
<input type="hidden" name="theme_background_operation" value="keep" data-visual-editor-background-operation><input type="hidden" name="theme_background_target" value="theme" data-visual-editor-background-target>
<section data-visual-editor-background-review><p data-visual-editor-background-target-review></p><p data-visual-editor-background-source-review></p><img data-visual-editor-current-background-preview><p data-visual-editor-background-empty hidden></p><img data-visual-editor-pending-background-preview hidden><p data-visual-editor-background-operation-review></p><button type="button" data-visual-editor-background-keep>Keep current</button><button type="button" data-visual-editor-background-remove>Remove background</button></section>
<p data-visual-editor-background-status></p><button type="button" data-visual-editor-launch disabled>Live Visual CSS Editor</button>
<button type="button" data-css-override-restore-saved>Restore saved CSS</button><button type="button" data-css-override-clear-draft>Clear draft</button>
<button type="button" data-css-override-undo-clear-draft hidden>Undo clear draft</button><p data-css-override-status></p><p data-css-override-message></p>
</section></main><pre id="results"></pre>
<script>
window.__visualEditorRuntimeError='';
window.__visualEditorTestReady=false;
window.addEventListener('error',event=>{const detail=String(event.error?.stack||event.message||'module or page script failed');window.__visualEditorRuntimeError=window.__visualEditorRuntimeError||detail;const output=document.getElementById('results');if(output&&!/^BROWSER (PASS|FAIL)/.test(output.textContent))output.textContent='BROWSER FAIL uncaught browser error: '+detail;});
window.addEventListener('unhandledrejection',event=>{const detail=String(event.reason?.stack||event.reason||'unknown rejection');window.__visualEditorRuntimeError=window.__visualEditorRuntimeError||detail;const output=document.getElementById('results');if(output&&!/^BROWSER (PASS|FAIL)/.test(output.textContent))output.textContent='BROWSER FAIL unhandled browser rejection: '+detail;});
window.__visualResizePendingRequest=null;
window.__visualResizeCompletion=null;
window.__visualResizePublishRequest=request=>{window.__visualResizePendingRequest=request;};
// Keep a bounded, content-free trace so a missing native resize preview identifies hit testing or capture failure.
// Type: Array<{type:'pointerdown'|'pointermove'|'pointerup'|'pointercancel'|'gotpointercapture'|'lostpointercapture',target:'handle'|'other',overlayTarget:boolean,overlayContainsTarget:boolean,edge:string,button:number,buttons:number,pointerId:number,pointerType:'mouse'|'pen'|'touch'|'other',isPrimary:boolean,trusted:boolean,targetConnected:boolean,captured:boolean}>.
// Units: button is the MouseEvent button code; buttons is the active-button bit mask; pointerId is the browser pointer identifier. Remaining fields are enums/booleans.
// Scope: The synthetic browser fixture document and one active gesture only.
// Consumers: The bounded resize gesture failure diagnostic below.
// Rationale: Distinguish native pointer delivery/capture from resize rendering without recording page text or waiting longer.
window.__visualResizePointerTrace=[];
window.__visualResizeBubbleTrace=[];
for(const type of ['pointerdown','pointermove','pointerup','pointercancel','gotpointercapture','lostpointercapture']){
 document.addEventListener(type,event=>{
  const handle=event.target instanceof Element?event.target.closest('.theme-visual-css-resize-handle'):null;
  const overlay=document.querySelector('.theme-visual-editor-resize-overlay');
  const frame=document.querySelector('.theme-visual-editor-workspace iframe');
  const selectedTarget=frame?.contentDocument?.querySelector('#hero');
  const trace=window.__visualResizePointerTrace;
  if(trace.length<12)trace.push({type,target:handle?'handle':'other',overlayTarget:Boolean(overlay&&event.target===overlay),overlayContainsTarget:Boolean(overlay&&overlay.contains(event.target)),edge:handle?.dataset.resizeEdge||'',button:Number(event.button),buttons:Number(event.buttons)||0,pointerId:Number(event.pointerId),pointerType:['mouse','pen','touch'].includes(event.pointerType)?event.pointerType:'other',isPrimary:Boolean(event.isPrimary),trusted:Boolean(event.isTrusted),targetConnected:Boolean(selectedTarget?.isConnected),captured:Boolean(window.__visualResizeHandle?.hasPointerCapture?.(event.pointerId))});
 },{capture:true,passive:true});
}
// Observe only pointerdown events that reach document bubbling; the resize handler stops accepted downs.
// Type: Array<{target:'handle'|'other',edge:string,button:number,defaultPrevented:boolean}>.
// Units: Mouse button code; remaining fields are enum/boolean.
// Scope: At most four pointerdown observations in this fixture document.
// Consumers: The resize failure diagnostic distinguishes an early guard return from an accepted handle gesture.
// Rationale: The production handler calls preventDefault and stopPropagation only after its pointer guards pass.
document.addEventListener('pointerdown',event=>{
 const handle=event.target instanceof Element?event.target.closest('.theme-visual-css-resize-handle'):null;
 const trace=window.__visualResizeBubbleTrace;
 if(trace.length<4)trace.push({target:handle?'handle':'other',edge:handle?.dataset.resizeEdge||'',button:Number(event.button),defaultPrevented:Boolean(event.defaultPrevented)});
},{passive:true});
window.__visualKeyboardPendingRequest=null;
window.__visualKeyboardCompletion=null;
window.__visualKeyboardPublishRequest=request=>{window.__visualKeyboardPendingRequest=request;};
</script>
<script type="module">
import {setupThemeCssOverrideEditor, setupOptionalThemeVisualEditor} from '/public/assets/gallery-modules/theme-customization.js?v=20261010-visual-editor-draft-availability';
import {setupThemeVisualEditor} from '/public/assets/gallery-modules/theme-visual-editor.js?v=20261010-visual-editor-draft-availability';
import {applyVisualCssDraftChanges, parseVisualCssDraft, serializeVisualCssDraft, visualCssDraftSelectorIsValid} from '/public/assets/gallery-modules/theme-visual-css-draft.js?v=20261009-visual-editor-overlay-routing';
import {classifyVisualCssResizeProfile, createVisualCssResizeTransaction} from '/public/assets/gallery-modules/theme-visual-css-resize.js?v=20261009-visual-editor-overlay-routing';
const root=document.querySelector('[data-css-override-editor]');
const text=root.querySelector('[data-css-override-text]');
root.dataset.visualEditorImportUnsupportedLabel='The visual editor cannot safely preview CSS with @import. Use Manual CSS, or remove the import rules before opening this preview.';
root.dataset.visualEditorInspectionUnavailableLabel='The visual editor cannot safely inspect the installed stylesheets. Check the saved CSS, or continue in Manual CSS.';
root.dataset.savedLabel='Saved';root.dataset.unsavedLabel='Unsaved changes';root.dataset.unsavedMessage='Unsaved changes';
const launchMessage=root.querySelector('[data-css-override-message]');
const originalDraft='body{--visual-probe:isolated;--visual-import-token:@import;--visual-import-url:url(data:text/plain,@import);--visual-import-escaped:' + String.fromCharCode(92) + '@import}.draft-target{color:rgb(1,2,3)}/* @import */';
text.value=originalDraft;
root.querySelector('[data-css-override-saved-text]').value=originalDraft;
window.__confirmCalls=0;
window.confirm=()=>{window.__confirmCalls++;return true;};
setupThemeCssOverrideEditor();
const preloadedFile=new File(['loading draft'],'loading-draft.png',{type:'image/png'});
const preloadedTransfer=new DataTransfer();preloadedTransfer.items.add(preloadedFile);
const preloadedInput=root.querySelector('[data-visual-editor-background-file]');
preloadedInput.files=preloadedTransfer.files;preloadedInput.dispatchEvent(new Event('change',{bubbles:true}));
if(root.querySelector('[data-visual-editor-background-operation]').value!=='replace')throw new Error('Background selection must stage synchronously before workspace loading');
await setupOptionalThemeVisualEditor(root,text);
if(preloadedInput.files[0]!==preloadedFile||root.querySelector('[data-visual-editor-background-operation]').value!=='replace'||text.value!==originalDraft)throw new Error('Workspace startup must preserve pending File, operation and CSS drafts');
root.querySelector('[data-visual-editor-background-keep]').click();
if(preloadedInput.files.length!==0||root.querySelector('[data-visual-editor-background-operation]').value!=='keep')throw new Error('Keep cancels the loading-time background draft');
window.__visualEditorTestReady=true;
/** Require an actual editor state transition or browser layout invariant. @param {boolean} condition Expected behavior. @param {string} message Failure context. @returns {void} Increments the fixture assertion count or throws. */
function check(condition,message){if(!condition)throw new Error(message);assertions++;}
/**
 * Bound navigation completion after the production iframe load listener has processed the new document.
 * Type: number. Units: milliseconds. Scope: one protected preview iframe transition.
 * Consumers: served-page navigation checks for explicit Follow and Home controls.
 * Rationale: fail a stalled transition before the outer browser fixture deadline.
 */
const frameTransitionTimeoutMs=5000;
/** Wait for the next owned iframe load event and release every listener when it settles.
 * @param {HTMLIFrameElement} frame Same-origin preview frame whose route or audience is changing.
 * @param {string} transition Safe action label used in bounded failure diagnostics.
 * @returns {Promise<void>} Resolves after earlier production load handlers complete, or rejects on load error or timeout.
 */
function waitForFrameLoad(frame,transition){return new Promise((resolve,reject)=>{let timer=0;const cleanup=()=>{clearTimeout(timer);frame.removeEventListener('load',loaded);frame.removeEventListener('error',failed);};const loaded=()=>{cleanup();resolve();};const failed=()=>{cleanup();reject(new Error('The preview frame failed to load after '+transition+'.'));};timer=setTimeout(()=>{cleanup();reject(new Error('The preview frame did not load after '+transition+'.'));},frameTransitionTimeoutMs);frame.addEventListener('load',loaded,{once:true});frame.addEventListener('error',failed,{once:true});});}
/** Wait until the production load handler has committed the public title and exact editor-owned CSS draft state.
 * @param {HTMLIFrameElement} frame Owned same-origin preview frame whose document is loading.
 * @param {string} title Expected server-rendered page title.
 * @returns {Promise<void>} Resolves when the complete public document and production HUD title are committed and its draft style matches the textarea (or is absent for empty CSS), or rejects at the existing bounded deadline.
 */
async function waitForFrame(frame,title='Fixture home'){const deadline=Date.now()+5000;while(Date.now()<deadline){const previewDocument=frame.contentDocument;const draftStyle=previewDocument?.querySelector('style[data-theme-visual-editor-draft]');const draftMatches=text.value===''?!draftStyle:draftStyle?.textContent===text.value;const hudPageTitle=document.querySelector('.theme-visual-editor-page-title')?.textContent;if(previewDocument?.title===title&&previewDocument.readyState==='complete'&&previewDocument.body?.classList.contains('public-page')&&hudPageTitle===title&&draftMatches)return;await new Promise(resolve=>setTimeout(resolve,25));}throw new Error('The public preview page '+title+' did not finish loading and applying the editor CSS draft.');}
/** Focus a native preview or inspector control and request one real browser key activation.
 * @param {HTMLElement} target Focusable element whose keyboard behavior is under review.
 * @param {'Enter'|'Space'} key Native browser key name to send to the focused control.
 * @param {string} phase Bounded failure context for the parent-driver handshake.
 * @returns {Promise<void>} Resolves after the parent driver completes the requested key press.
 */
async function pressNativeKey(target,key,phase){target.focus({preventScroll:true});await new Promise(resolve=>{window.__visualKeyboardCompletion=resolve;window.__visualKeyboardPublishRequest({key,phase});});}
let assertions=0;
try{
 const launch=root.querySelector('[data-visual-editor-launch]');
 check(!launch.hidden&&!launch.disabled,'launch button appears and enables when a protected preview URL exists');
 const initialLocation=location.href;
 text.value='/* unsaved manual edit */';text.dispatchEvent(new Event('input',{bubbles:true}));
 root.querySelector('[data-css-override-restore-saved]').click();
 check(text.value===originalDraft&&root.querySelector('[data-css-override-revision]').value==='saved-revision','Restore saved CSS uses the last server-rendered text and revision snapshot');
 check(window.__confirmCalls===1&&location.href===initialLocation,'Restore saved CSS confirms dirty replacement without navigating or submitting');
 text.value='/* clear draft snapshot */';text.dispatchEvent(new Event('input',{bubbles:true}));
 const lifecycleFile=root.querySelector('[data-visual-editor-background-file]');
 const clearFile=new File(['clear snapshot image'],'clear-snapshot.png',{type:'image/png'});
 const clearTransfer=new DataTransfer();clearTransfer.items.add(clearFile);lifecycleFile.files=clearTransfer.files;
 lifecycleFile.dispatchEvent(new Event('change',{bubbles:true}));
 root.querySelector('[data-css-override-clear-draft]').click();
 check(text.value===''&&lifecycleFile.files.length===0&&root.querySelector('[data-visual-editor-background-operation]').value==='keep'
     &&root.querySelector('[data-css-override-status]').textContent==='Unsaved changes','Clear draft empties CSS, File, and staged operation state while remaining dirty');
 check(!root.querySelector('[data-css-override-undo-clear-draft]').hidden,'Clear draft exposes a one-step Undo control');
 root.querySelector('[data-css-override-undo-clear-draft]').click();
 check(text.value==='/* clear draft snapshot */'&&lifecycleFile.files[0]?.name==='clear-snapshot.png'
     &&root.querySelector('[data-visual-editor-background-operation]').value==='replace'
     &&root.querySelector('[data-css-override-undo-clear-draft]').hidden,'Undo clear draft restores the exact CSS and File snapshots');
 root.querySelector('[data-css-override-restore-saved]').click();
 check(text.value===originalDraft&&location.href===initialLocation,'Restore saved CSS can discard the restored clear snapshot without changing the Admin URL');
 launch.click();
 const operationOnlyDialog=document.querySelector('.theme-visual-editor-workspace');
 const operationOnlyFrame=operationOnlyDialog.querySelector('iframe');
 await waitForFrame(operationOnlyFrame);
 const operationOnlyLink=operationOnlyFrame.contentDocument.querySelector('#gallery-link');
 const operationOnlyFit=operationOnlyDialog.querySelector('[data-visual-editor-background-fit]');
 const operationOnlyFitConflict=operationOnlyDialog.querySelector('[data-visual-editor-background-override-conflict]');
 const operationOnlyConflictStyle=operationOnlyFrame.contentDocument.createElement('style');
 operationOnlyConflictStyle.textContent='.theme-background-image{background-size:cover!important}';
 operationOnlyFrame.contentDocument.head.append(operationOnlyConflictStyle);
 operationOnlyFit.value='contain';operationOnlyFit.dispatchEvent(new Event('change',{bubbles:true}));
 check(operationOnlyFrame.contentWindow.getComputedStyle(operationOnlyFrame.contentDocument.querySelector('.theme-background-image')).backgroundSize==='cover'
     &&!operationOnlyFitConflict.hidden,
     'a stronger global Theme fit declaration exposes the conflict status before route navigation');
 await pressNativeKey(operationOnlyLink,'Enter','select linked preview anchor with Enter');
 const operationOnlyInspector=operationOnlyDialog.querySelector('.theme-visual-editor-inspector');
 const operationOnlyStyle=[...operationOnlyInspector.querySelectorAll('button')].find(button=>button.textContent==='Style element');
 const operationOnlyFollow=operationOnlyDialog.querySelector('[data-visual-editor-follow-link]');
 check(operationOnlyFrame.contentDocument.querySelector('[data-visual-selected]')?.id==='gallery-link'
     &&Boolean(operationOnlyStyle&&!operationOnlyStyle.disabled)
     &&Boolean(operationOnlyFollow&&!operationOnlyFollow.hidden&&operationOnlyFollow.dataset.destination.includes('/app/gallery/album/')),
     'Enter selects a focused gallery anchor without activating it and exposes keyboard Style and protected Follow controls');
 const operationOnlyAncestor=[...operationOnlyInspector.querySelectorAll('.theme-visual-editor-ancestors button')]
     .find(button=>button.textContent==='article#fixture-gallery-card.gallery-card');
 await pressNativeKey(operationOnlyAncestor,'Space','select gallery-card ancestor with Space');
 check(operationOnlyFrame.contentDocument.querySelector('[data-visual-selected]')?.id==='fixture-gallery-card'
     &&operationOnlyStyle.isConnected&&operationOnlyFollow.isConnected
     &&operationOnlyFollow.hidden&&!operationOnlyFollow.dataset.destination,
     'Space activates the focused structural ancestor control and removes Follow for the unlinked card');
 operationOnlyLink.focus({preventScroll:true});
 await pressNativeKey(operationOnlyLink,'Space','reselect gallery anchor with Space');
 check(operationOnlyFrame.contentDocument.querySelector('[data-visual-selected]')?.id==='gallery-link'
     &&operationOnlyStyle.isConnected&&operationOnlyFollow.isConnected&&!operationOnlyFollow.hidden,
     'Space selects a focused preview link without activating its destination');
 operationOnlyStyle.focus({preventScroll:true});
 await pressNativeKey(operationOnlyStyle,'Enter','open Style element with Enter');
 const operationOnlyProperties=operationOnlyInspector.querySelector('.theme-visual-editor-properties');
 const operationOnlyStylePressed=operationOnlyStyle.getAttribute('aria-pressed');
 check(operationOnlyStyle.isConnected&&operationOnlyInspector.contains(operationOnlyStyle)
     &&operationOnlyStylePressed==='true'&&!operationOnlyProperties.hidden,
     'Enter activates the focused Style element button and exposes its keyboard-operable controls'
         +' (style-connected='+Boolean(operationOnlyStyle.isConnected&&operationOnlyInspector.contains(operationOnlyStyle))
         +', aria-pressed='+(operationOnlyStylePressed||'missing')+', properties-hidden='+operationOnlyProperties.hidden+')');
 const operationOnlyGalleryLoad=waitForFrameLoad(operationOnlyFrame,'keyboard Follow Link to the gallery');
 operationOnlyFollow.focus({preventScroll:true});
 await pressNativeKey(operationOnlyFollow,'Space','follow gallery link with Space');
 await operationOnlyGalleryLoad;
 check(operationOnlyFrame.contentDocument?.title==='Fixture gallery'
     &&operationOnlyFrame.contentWindow.location.pathname==='/app/gallery/album/'
     &&operationOnlyFrame.contentWindow.location.search.includes('preview=visual'),
     'Space activates the focused Follow control and commits the allowlisted gallery document');
 check(root.querySelector('[data-visual-editor-background-status]').textContent.includes('open Home')
     &&operationOnlyDialog.querySelector('[data-visual-editor-background-choose]').disabled
     &&operationOnlyDialog.querySelector('[data-visual-editor-remove-background]').disabled,
     'a gallery-specific source explains the Home target even when the current operation is Keep');
 const operationOnlyPositionX=operationOnlyDialog.querySelector('[data-visual-editor-background-position-x]');
 const operationOnlyPositionY=operationOnlyDialog.querySelector('[data-visual-editor-background-position-y]');
 const operationOnlyPositionXNumber=operationOnlyDialog.querySelector('[data-visual-editor-background-position-x-number]');
 const operationOnlyPositionYNumber=operationOnlyDialog.querySelector('[data-visual-editor-background-position-y-number]');
 const operationOnlyBackground=operationOnlyFrame.contentDocument.querySelector('.theme-background-image');
 const galleryBackgroundSize=operationOnlyFrame.contentWindow.getComputedStyle(operationOnlyBackground).backgroundSize;
 const galleryBackgroundPosition=operationOnlyFrame.contentWindow.getComputedStyle(operationOnlyBackground).backgroundPosition;
 check(getComputedStyle(operationOnlyFit.closest('fieldset')).display==='none'
     &&getComputedStyle(operationOnlyPositionX.closest('fieldset')).display==='none'
     &&getComputedStyle(operationOnlyPositionY.closest('fieldset')).display==='none'
     &&getComputedStyle(operationOnlyPositionXNumber.closest('fieldset')).display==='none'
     &&getComputedStyle(operationOnlyPositionYNumber.closest('fieldset')).display==='none'
     &&getComputedStyle(operationOnlyFitConflict).display==='none'
     &&operationOnlyFit.closest('fieldset').hidden&&operationOnlyPositionX.closest('fieldset').hidden
     &&operationOnlyPositionY.closest('fieldset').hidden
     &&operationOnlyPositionXNumber.closest('fieldset').hidden
     &&operationOnlyPositionYNumber.closest('fieldset').hidden
     &&operationOnlyFitConflict.hidden,
     'target=none removes the conflicted global fit status and visually hides global fit and position controls');
 operationOnlyFit.value='contain';operationOnlyFit.dispatchEvent(new Event('change',{bubbles:true}));
 operationOnlyPositionX.value='100';operationOnlyPositionX.dispatchEvent(new Event('input',{bubbles:true}));
 operationOnlyPositionX.dispatchEvent(new Event('change',{bubbles:true}));
 operationOnlyPositionY.value='0';operationOnlyPositionY.dispatchEvent(new Event('input',{bubbles:true}));
 operationOnlyPositionY.dispatchEvent(new Event('change',{bubbles:true}));
 check(operationOnlyFrame.contentWindow.getComputedStyle(operationOnlyBackground).backgroundSize===galleryBackgroundSize
     &&operationOnlyFrame.contentWindow.getComputedStyle(operationOnlyBackground).backgroundPosition===galleryBackgroundPosition
     &&operationOnlyBackground.style.backgroundSize===''&&operationOnlyBackground.style.backgroundPosition===''
     &&text.value===originalDraft&&root.querySelector('[data-visual-editor-background-operation]').value==='keep',
     'hidden global fit and position controls cannot alter or serialize an independent gallery background');
 const operationOnlyHomeLoad=waitForFrameLoad(operationOnlyFrame,'return to Home');
 [...operationOnlyDialog.querySelectorAll('button')].find(button=>button.textContent==='Home').click();
 await operationOnlyHomeLoad;
 check(operationOnlyFrame.contentDocument?.title==='Fixture home','Home commits the Theme document before applying the pending operation');
 operationOnlyConflictStyle.remove();
 operationOnlyDialog.querySelector('[data-visual-editor-background-reset-fit]').click();
 check(operationOnlyFit.value==='cover'
     &&operationOnlyDialog.querySelector('[data-visual-editor-background-reset-fit]').disabled
     &&operationOnlyFrame.contentWindow.getComputedStyle(
         operationOnlyFrame.contentDocument.querySelector('.theme-background-image')).backgroundSize==='cover'
     &&text.value===originalDraft,
     'the route-conflict probe removes only its temporary managed fit rule and restores the exact entry CSS state');
 operationOnlyDialog.querySelector('[data-visual-editor-remove-background]').click();
 check(root.querySelector('[data-visual-editor-background-operation]').value==='remove'
     &&lifecycleFile.files.length===0&&operationOnlyFrame.contentWindow.getComputedStyle(
         operationOnlyFrame.contentDocument.querySelector('.theme-background-image')).backgroundImage==='none',
     'Remove creates a dirty global operation even when no File or CSS changes');
 operationOnlyDialog.querySelector('[data-visual-editor-cancel-exit]').click();
 check(!operationOnlyDialog.querySelector('[data-visual-editor-exit-prompt]').hidden,
     'a removal-only operation receives the dirty-session exit choices');
 operationOnlyDialog.querySelector('[data-visual-editor-discard-session]').click();
 check(root.querySelector('[data-visual-editor-background-operation]').value==='keep'&&lifecycleFile.files.length===0
     &&text.value===originalDraft,'Discard restores the entry Keep operation and original CSS without saving');
 const importDraft='/* @import is inert here */ .draft-target{content:"@import"}'+String.fromCharCode(10)+'@'+String.fromCharCode(92)+'69mport url("theme.css");';
 text.value=importDraft;
 launch.click();
 check(!document.querySelector('.theme-visual-editor-workspace'),'CSS with an escaped @import is refused before a preview workspace opens');
 check(text.value===importDraft,'the @import refusal preserves exact CSS textarea bytes');
 check(launchMessage.textContent===root.dataset.visualEditorImportUnsupportedLabel,'the import refusal uses the localized explanation');
 text.value=originalDraft;
 const rootMountProbe=document.createElement('section');
 rootMountProbe.dataset.visualEditorPreviewUrl='/index.php?page=home&preview=visual';
 const rootMountText=document.createElement('textarea');
 const rootMountLaunch=document.createElement('button');
 rootMountLaunch.type='button';
 rootMountLaunch.dataset.visualEditorLaunch='';
 rootMountLaunch.hidden=true;
 rootMountProbe.append(rootMountText,rootMountLaunch);
 setupThemeVisualEditor(rootMountProbe,rootMountText);
 check(!rootMountLaunch.hidden,'controller-prepared homepage is accepted when the app is mounted at the origin root');
 const externalProbe=document.createElement('section');
 externalProbe.dataset.visualEditorPreviewUrl='https://example.invalid/index.php?page=home&preview=visual';
 const externalText=document.createElement('textarea');
 const externalLaunch=document.createElement('button');
 externalLaunch.type='button';
 externalLaunch.dataset.visualEditorLaunch='';
 externalLaunch.hidden=true;
 externalProbe.append(externalText,externalLaunch);
 setupThemeVisualEditor(externalProbe,externalText);
 check(!externalLaunch.hidden&&externalLaunch.disabled&&externalLaunch.dataset.visualEditorReady!=='1','initial route validation rejects an external origin with a visible disabled launcher');
 const invalidPreviewScenarios=[
  ['https://example.invalid/index.php?page=home&preview=visual','preview_origin_mismatch'],
  ['/app/index.php?page=home','preview_marker_missing'],
  ['http://[','preview_url_invalid'],
  ['/app/index.php?page=home&preview=visual&preview=visual','preview_url_invalid'],
  ['/app/index.php?page=admin&preview=visual','preview_url_invalid'],
  ['/app/index.php?page=home&preview=visual#fragment','preview_url_invalid'],
  [location.origin.replace('http:','https:')+'/app/index.php?page=home&preview=visual','preview_origin_mismatch'],
  ['http://127.0.0.1:1/app/index.php?page=home&preview=visual','preview_origin_mismatch'],
 ];
 for(const [url,reason] of invalidPreviewScenarios){
  const probe=document.createElement('section');
  probe.dataset.visualEditorPreviewUrl=url;
  probe.innerHTML='<textarea>preserved draft</textarea><button type="button" data-visual-editor-launch disabled>Visual</button><span hidden data-visual-editor-availability-labels></span><p data-visual-editor-availability></p>';
  const probeText=probe.querySelector('textarea');
  const probeStatus=probe.querySelector('[data-visual-editor-availability]');
  probe.querySelector('[data-visual-editor-availability-labels]').setAttribute('data-'+reason.replaceAll('_','-'),'Localized bounded reason');
  setupThemeVisualEditor(probe,probeText);
  setupThemeVisualEditor(probe,probeText);
  const probeLaunch=probe.querySelector('button');
  check(!probeLaunch.hidden&&probeLaunch.disabled&&probeStatus.dataset.visualEditorReason===reason&&probeStatus.textContent==='Localized bounded reason','invalid initial URL reports only its bounded translated category');
  probeLaunch.click();
  check(probeText.value==='preserved draft'&&!document.querySelector('.theme-visual-editor-workspace'),'refused launcher cannot open or change the draft');
 }

 launch.click();
 const dialog=document.querySelector('.theme-visual-editor-workspace');
 check(dialog instanceof HTMLDialogElement&&dialog.open,'launch opens a viewport workspace');
 const frame=dialog.querySelector('iframe');
 check(frame.getAttribute('sandbox')==='allow-same-origin','iframe enables same-origin parent inspection without scripts or forms');
 await waitForFrame(frame);
 const collapseControl=dialog.querySelector('[data-visual-editor-collapse-controls]');
 collapseControl.click();
 check(dialog.querySelector('.theme-visual-editor-hud').dataset.collapsed==='1'&&collapseControl.getAttribute('aria-expanded')==='false','HUD controls collapse with an accessible expanded state');
 collapseControl.click();
 check(dialog.querySelector('.theme-visual-editor-hud').dataset.collapsed==='0'&&collapseControl.getAttribute('aria-expanded')==='true','HUD controls can be restored without closing the preview');
 check(frame.contentDocument.scripts.length===0,'public preview response contains no executable script');
 check(getComputedStyle(frame.contentDocument.querySelector('.draft-target')).color==='rgb(1, 2, 3)','unsaved editor CSS is applied within the public frame');
 check(getComputedStyle(document.body).getPropertyValue('--visual-probe')==='','broad draft selectors stay inside the iframe');
 const detachedTarget=frame.contentDocument.createElement('p');
 detachedTarget.textContent='Temporary selection';frame.contentDocument.body.append(detachedTarget);detachedTarget.click();detachedTarget.remove();
 window.dispatchEvent(new Event('resize'));
check(dialog.querySelector('.theme-visual-editor-inspector').hidden
    &&dialog.querySelector('.theme-visual-editor-draft-status').textContent===root.querySelector('[data-visual-editor-lifecycle-labels]').dataset.visualEditorSelectionUnavailableLabel,
     'a disconnected iframe selection is cleared and explained before stale controls can be used');
 const initialUrl=frame.contentWindow.location.href;
 frame.contentDocument.querySelector('a').click();
 frame.contentDocument.querySelector('form').requestSubmit();
 await new Promise(resolve=>setTimeout(resolve,60));
 check(frame.contentWindow.location.href===initialUrl,'ordinary links and form submissions stay inside inspection mode');
 const inspector=dialog.querySelector('.theme-visual-editor-inspector');
 check(!inspector.hidden&&frame.contentDocument.querySelector('[data-visual-selected]')?.id==='gallery-link'
     &&inspector.querySelector('code').textContent==='body.public-page #gallery-link',
     'iframe-realm target clicks populate the parent inspector with the stable, public-page-scoped selector');
 check(inspector.textContent.includes('Matches: 1'),'inspector reports the actual selector match count');
 check(!inspector.querySelector('[data-destination]')?.hidden,'server-emitted clean gallery anchors offer explicit Follow Link');
 const hoverTarget=frame.contentDocument.querySelector('#draft-target');
 hoverTarget.dispatchEvent(new frame.contentWindow.PointerEvent('pointerover',{bubbles:true}));
 const hoverTip=dialog.querySelector('.theme-visual-editor-hover-tip');
 check(!hoverTip.hidden&&hoverTip.textContent.startsWith('#draft-target'),'iframe pointer hover shows a parent-owned selector tooltip');
 hoverTarget.dispatchEvent(new frame.contentWindow.PointerEvent('pointerout',{bubbles:true}));
 check(hoverTip.hidden,'leaving the hovered target dismisses its tooltip');
 const follow=inspector.querySelector('[data-visual-editor-follow-link]');
 const galleryLoad=waitForFrameLoad(frame,'Follow Link to the gallery');
 follow.click();
 await galleryLoad;
 await waitForFrame(frame,'Fixture gallery');
 check(frame.contentWindow.location.pathname==='/app/gallery/album/','explicit Follow accepts an allowlisted clean gallery URL under a subdirectory mount');
 check(frame.contentWindow.location.search.includes('preview=visual'),'Follow preserves the protected visual preview marker');
 check(getComputedStyle(frame.contentDocument.querySelector('.draft-target')).color==='rgb(1, 2, 3)','navigation reapplies the current CSS draft to the new document');
 const paginationLink=frame.contentDocument.querySelector('#home-pagination-link');
 paginationLink.click();
 const paginationInspector=dialog.querySelector('.theme-visual-editor-inspector');
 const paginationFollow=[...paginationInspector.querySelectorAll('button')].find(button=>button.textContent==='Follow link');
 check(Boolean(paginationFollow),'parser-approved clean home pagination links are available as explicit navigation');
 const homePaginationLoad=waitForFrameLoad(frame,'Follow Link to home pagination');
 paginationFollow.click();
 await homePaginationLoad;
 await waitForFrame(frame,'Fixture home');
 check(frame.contentWindow.location.pathname==='/app/galleries/2/','clean home pagination remains within the mounted homepage route');
 const homeLoad=waitForFrameLoad(frame,'Back to the previous gallery');
 [...dialog.querySelectorAll('button')].find(button=>button.textContent==='Back').click();
 await homeLoad;
 await waitForFrame(frame,'Fixture gallery');
 check(frame.contentWindow.location.pathname==='/app/gallery/album/'
     &&frame.contentWindow.location.search.includes('preview=visual'),
     'Back returns to the immediately previous protected gallery route, preserving the preview marker');
 let links=frame.contentDocument;
 const assertNoFollow=(id,reason)=>{
  const target=links.querySelector(id);
  target.click();
  const styleAction=[...inspector.querySelectorAll('button')].find(button=>button.textContent==='Style element');
  const followAction=inspector.querySelector('[data-visual-editor-follow-link]');
  check(frame.contentDocument.querySelector('[data-visual-selected]')===target
      &&Boolean(styleAction&&!styleAction.disabled)
      &&Boolean(followAction?.hidden&&!followAction.dataset.destination),reason);
 };
 assertNoFollow('#unsafe-link','external anchors remain selectable for styling but have no Follow destination');
 assertNoFollow('#download-link','download anchors remain selectable for styling but have no Follow destination');
 assertNoFollow('#admin-link','Admin routes remain selectable for styling but have no Follow destination');
 assertNoFollow('#target-link','new-window anchors remain selectable for styling but have no Follow destination');
 assertNoFollow('#unlinked','unlinked targets remain selectable for styling but have no Follow destination');
 links.querySelector('#refusal-import-link').click();
 const importFollow=inspector.querySelector('[data-visual-editor-follow-link]');
 const importRefusalLoad=waitForFrameLoad(frame,'open the server-marked import refusal');
 importFollow.click();
 await importRefusalLoad;
 check(dialog.querySelector('.theme-visual-editor-draft-status').textContent===root.dataset.visualEditorImportUnsupportedLabel
     &&text.value===originalDraft,'a server 409 import refusal uses its translated HUD reason and preserves the textarea draft');
 const backFromImport=waitForFrameLoad(frame,'return from the import refusal');
 dialog.querySelector('[data-visual-editor-back]').click();
 await backFromImport;
 await waitForFrame(frame,'Fixture gallery');
 links=frame.contentDocument;
 links.querySelector('#refusal-inspection-link').click();
 const inspectionFollow=inspector.querySelector('[data-visual-editor-follow-link]');
 const inspectionRefusalLoad=waitForFrameLoad(frame,'open the server-marked inspection refusal');
 inspectionFollow.click();
 await inspectionRefusalLoad;
 check(dialog.querySelector('.theme-visual-editor-draft-status').textContent===root.dataset.visualEditorInspectionUnavailableLabel
     &&text.value===originalDraft,'a bounded inspection refusal shows its own localized reason without replacing the draft');
 const backFromInspection=waitForFrameLoad(frame,'return from the inspection refusal');
 dialog.querySelector('[data-visual-editor-back]').click();
 await backFromInspection;
 await waitForFrame(frame,'Fixture gallery');
 links=frame.contentDocument;
 links.querySelector('#refusal-unknown-link').click();
 const unknownFollow=inspector.querySelector('[data-visual-editor-follow-link]');
 const unknownRefusalLoad=waitForFrameLoad(frame,'open the unknown server refusal');
 unknownFollow.click();
 await unknownRefusalLoad;
 check(dialog.querySelector('.theme-visual-editor-draft-status').textContent===root.dataset.visualEditorInspectionUnavailableLabel
     &&text.value===originalDraft,'an unknown refusal reason fails closed with generic localized status and preserves draft bytes');
 const backFromUnknown=waitForFrameLoad(frame,'return from the unknown refusal');
 dialog.querySelector('[data-visual-editor-back]').click();
 await backFromUnknown;
 await waitForFrame(frame,'Fixture gallery');
 links=frame.contentDocument;
 links.querySelector('#empty-background').click();
 check(inspector.querySelector('strong').textContent==='Page background','intentional blank main-area clicks enter background inspection');
 const styleButton=[...inspector.querySelectorAll('button')].find(button=>button.textContent==='Style element');
 const cssBeforeStyle=text.value;
 styleButton.click();
 const styleTarget=frame.contentDocument.querySelector('[data-visual-selected]');
 const styleComputed=styleTarget?frame.contentWindow.getComputedStyle(styleTarget):null;
 const styleFields=[...inspector.querySelectorAll('.theme-visual-editor-property')];
 const styleReadoutsMatch=Boolean(styleComputed&&styleFields.length>0&&styleFields.every(field=>{
  const property=field.querySelector('legend')?.textContent||'';
  const effective=field.querySelector('span code')?.textContent||'';
  return property!==''&&effective===styleComputed.getPropertyValue(property).trim();
 }));
 check(inspector.dataset.mode==='style'&&!inspector.querySelector('.theme-visual-editor-properties').hidden
     &&styleReadoutsMatch&&text.value===cssBeforeStyle,
     'Style element exposes actual computed property values without changing the CSS draft');
 const mobile=[...dialog.querySelectorAll('button')].find(button=>button.textContent==='Mobile');
 mobile.click();
 check(Math.abs(frame.getBoundingClientRect().width-390)<1,'mobile preset changes the real iframe viewport width');
 const anonymous=[...dialog.querySelectorAll('button')].find(button=>button.textContent==='Anonymous view');
 links=frame.contentDocument;
 links.querySelector('#private-gallery-link').click();
 const privateFollow=inspector.querySelector('[data-visual-editor-follow-link]');
 const privateGalleryLoad=waitForFrameLoad(frame,'open the gated gallery in signed-in preview');
 privateFollow.click();
 await privateGalleryLoad;
 check(frame.contentWindow.location.pathname==='/app/gallery/private/'&&frame.contentDocument.body.dataset.audience==='signed-in',
     'the signed-in preview can inspect the private gallery before changing audience');
 const fallbackFileInput=dialog.querySelector('[data-visual-editor-background-file]');
 const fallbackFile=new File(['pending fallback image'],'fallback.png',{type:'image/png'});
 const fallbackTransfer=new DataTransfer();fallbackTransfer.items.add(fallbackFile);
 fallbackFileInput.files=fallbackTransfer.files;
 fallbackFileInput.dispatchEvent(new Event('change',{bubbles:true}));
 const undoBeforeFallback=[...dialog.querySelectorAll('button')].find(button=>button.textContent==='Undo');
 const redoBeforeFallback=[...dialog.querySelectorAll('button')].find(button=>button.textContent==='Redo');
 const cssBeforeFallback=text.value;
 check(fallbackFileInput.files[0]===fallbackFile&&root.querySelector('[data-visual-editor-background-operation]').value==='replace'
     &&!undoBeforeFallback.disabled,'a pending replacement establishes an observable editor-history snapshot before audience fallback');
 const anonymousLoad=waitForFrameLoad(frame,'switch to anonymous audience and follow the safe server fallback');
 anonymous.click();
 await anonymousLoad;
 check(frame.contentDocument.title==='Fixture home'&&frame.contentDocument.body.dataset.audience==='anonymous'
     &&frame.contentWindow.location.pathname==='/app/index.php'
     &&frame.contentWindow.location.search.includes('view_as=anonymous')
     &&!frame.contentWindow.location.search.includes('visual_notice')
     &&dialog.querySelector('.theme-visual-editor-draft-status').textContent===root.querySelector('[data-visual-editor-extension-labels]').dataset.visualEditorAnonymousFallbackNoticeLabel,
     'a denied anonymous gallery switches to its safe server-selected page and shows only the localized generic notice');
 check(fallbackFileInput.files[0]===fallbackFile&&root.querySelector('[data-visual-editor-background-operation]').value==='replace'
     &&text.value===cssBeforeFallback&&!undoBeforeFallback.disabled,
     'anonymous fallback preserves the pending File, operation, exact CSS bytes, and existing history');
 undoBeforeFallback.click();
 check(fallbackFileInput.files.length===0&&root.querySelector('[data-visual-editor-background-operation]').value==='keep'
     &&!redoBeforeFallback.disabled,'Undo after fallback restores the prior background snapshot without losing the forward branch');
 redoBeforeFallback.click();
 check(fallbackFileInput.files[0]===fallbackFile&&root.querySelector('[data-visual-editor-background-operation]').value==='replace'
     &&frame.contentWindow.getComputedStyle(frame.contentDocument.querySelector('.theme-background-image')).backgroundImage.includes('blob:'),
     'Redo after fallback restores the exact pending File preview and operation');
 const back=[...dialog.querySelectorAll('button')].find(button=>button.textContent==='Back');
 const backLoad=waitForFrameLoad(frame,'Back to the signed-in private gallery');
 back.click();
 await backLoad;
 check(frame.contentDocument.title==='Fixture gallery'&&frame.contentWindow.location.pathname==='/app/gallery/private/'
     &&frame.contentDocument.body.dataset.audience==='signed-in'
     &&!frame.contentWindow.location.search.includes('visual_notice')&&fallbackFileInput.files[0]===fallbackFile
     &&text.value===cssBeforeFallback,'Back restores the authenticated route without carrying the one-time notice or discarding editor drafts');
 const redirectLoad=waitForFrameLoad(frame,'inspect the redirected route');
 frame.contentDocument.querySelector('#redirect-link').click();
 inspector.querySelector('[data-visual-editor-follow-link]').click();
 await redirectLoad;
 const blockedDeadline=Date.now()+3000;
 while(Date.now()<blockedDeadline&&frame.getAttribute('src')!=='about:blank')await new Promise(resolve=>setTimeout(resolve,20));
 check(frame.getAttribute('src')==='about:blank'&&dialog.querySelector('.theme-visual-editor-draft-status').textContent.includes('outside the protected preview')
     &&root.querySelector('[data-visual-editor-background-status]').textContent.includes('open Home'),
     'a post-redirect route outside the mounted public allowlist is blanked and the global image editor fails closed');
 dialog.querySelector('[data-visual-editor-cancel-exit]').click();
 const fallbackExitPrompt=dialog.querySelector('[data-visual-editor-exit-prompt]');
 check(Boolean(fallbackExitPrompt&&!fallbackExitPrompt.hidden&&dialog.isConnected),
     'Cancel and exit offers an explicit discard choice for the pending File and operation');
 fallbackExitPrompt.querySelector('[data-visual-editor-discard-session]').click();
 check(!dialog.isConnected,'exit removes the visual workspace before returning to the CSS editor');
 const focusRestoreFlags=[text.isConnected,!text.disabled,text.getClientRects().length>0,document.activeElement===text,dialog.isConnected];
 check(focusRestoreFlags[3],
     'exit restores focus to the authoritative CSS editor (connected enabled visible active workspace '
         +focusRestoreFlags.map(Number).join('')+')');
 check(text.value===originalDraft&&fallbackFileInput.files.length===0
     &&root.querySelector('[data-visual-editor-background-operation]').value==='keep',
     'Discard restores the exact entry CSS and Keep/File state without saving');

 const stage3Launch=root.querySelector('[data-visual-editor-launch]');
 const backgroundInput=root.querySelector('[data-visual-editor-background-file]');
 check(backgroundInput instanceof HTMLInputElement&&backgroundInput.type==='file'&&backgroundInput.isConnected,
     'the fixture captures the real native background File input before the editor moves it into the HUD');
 stage3Launch.click();
 const stage3Dialog=document.querySelector('.theme-visual-editor-workspace');
  check(stage3Dialog.querySelector('.theme-visual-editor-hud')?.contains(backgroundInput)===true,
      'the active workspace owns the same native file input after moving it into the HUD');
  const stage3Frame=stage3Dialog.querySelector('iframe');
  window.__visualResizeCaptureTrace={installed:false,called:false,outcome:'not_called',error:'',pointerId:null,hasCaptureAfterReturn:false,handleConnectedAfterReturn:false,ownerDocumentMatchesAfterReturn:false};
  window.__visualResizeHandle=null;
  window.__visualResizeOwnerDocument=null;
  window.__visualResizeReadCaptureState=phase=>{
   const handle=window.__visualResizeHandle;
   const ownerDocument=window.__visualResizeOwnerDocument;
   const pointerId=window.__visualResizeCaptureTrace?.pointerId;
   return {phase,pointerId:Number.isInteger(pointerId)?pointerId:null,
    hasPointerCapture:Boolean(handle&&Number.isInteger(pointerId)&&handle.hasPointerCapture(pointerId)),
    handleConnected:Boolean(handle?.isConnected),ownerDocumentMatches:Boolean(handle&&ownerDocument&&handle.ownerDocument===ownerDocument),
    ownerDocumentIsCurrent:Boolean(ownerDocument&&ownerDocument===document),documentHasFocus:Boolean(ownerDocument?.hasFocus()),
    activeElementIsHandle:Boolean(ownerDocument&&ownerDocument.activeElement===handle)};
  };
  window.__visualResizeEligibility=(x,y,edge)=>{
   const target=stage3Frame.contentDocument?.querySelector('#hero');
   const rect=target?.getBoundingClientRect();
   const selector=stage3Dialog.querySelector('.theme-visual-editor-inspector code')?.textContent||'';
   const scope=stage3Dialog.querySelector('.theme-visual-editor-inspector select')?.value||'';
   const profile=classifyVisualCssResizeProfile(target);
   const transaction=target&&rect?createVisualCssResizeTransaction(profile,rect,{x,y},edge,selector,scope):null;
   return {targetConnected:Boolean(target?.isConnected),rectPositive:Boolean(rect&&rect.width>0&&rect.height>0),
    selectorValid:visualCssDraftSelectorIsValid(selector).valid,scopeAllowed:['site','responsive:tablet','responsive:mobile'].includes(scope),
    profile:{eligible:profile.eligible,reason:profile.reason,profileId:profile.profileId,bottomEdge:profile.edges.includes('bottom'),
     verticalProperty:profile.verticalProperty,minHeight:profile.minHeight,maxHeight:profile.maxHeight},transaction:Boolean(transaction)};
  };
  window.__visualResizeOverlayState=(phase,x,y)=>{
   const overlay=document.querySelector('.theme-visual-editor-resize-overlay');
   const hit=document.elementFromPoint(x,y);
   return {phase,connected:Boolean(overlay?.isConnected),computedPointerEvents:overlay?getComputedStyle(overlay).pointerEvents:'missing',
    inlinePointerEvents:overlay?.style.pointerEvents||'',hitInsideOverlay:Boolean(overlay&&hit&&overlay.contains(hit))};
  };
  window.__visualResizeInstallCaptureProbe=(x,y)=>{
   const hit=document.elementFromPoint(x,y);
   const handle=hit instanceof Element?hit.closest('.theme-visual-css-resize-handle'):null;
   if(!handle||typeof handle.setPointerCapture!=='function')return false;
   window.__visualResizeHandle=handle;
   window.__visualResizeOwnerDocument=handle.ownerDocument;
   const trace=window.__visualResizeCaptureTrace;
   const originalDescriptor=Object.getOwnPropertyDescriptor(handle,'setPointerCapture');
   const original=handle.setPointerCapture;
   const restore=()=>{
    if(originalDescriptor)Object.defineProperty(handle,'setPointerCapture',originalDescriptor);
    else delete handle.setPointerCapture;
   };
   const forward=function(pointerId){
    restore();trace.called=true;
    trace.pointerId=Number(pointerId);
    try{const result=Reflect.apply(original,this,[pointerId]);trace.outcome='returned';trace.hasCaptureAfterReturn=Boolean(handle.hasPointerCapture(pointerId));trace.handleConnectedAfterReturn=Boolean(handle.isConnected);trace.ownerDocumentMatchesAfterReturn=handle.ownerDocument===window.__visualResizeOwnerDocument;return result;}
    catch(error){trace.outcome='threw';trace.error=typeof error?.name==='string'?error.name:'Error';trace.handleConnectedAfterReturn=Boolean(handle.isConnected);trace.ownerDocumentMatchesAfterReturn=handle.ownerDocument===window.__visualResizeOwnerDocument;throw error;}
   };
   Object.defineProperty(handle,'setPointerCapture',{configurable:true,writable:true,value:forward});
   trace.installed=true;
   return true;
  };
  await waitForFrame(stage3Frame);
 const widthPrecedenceHint=stage3Dialog.querySelector('[data-visual-editor-width-precedence-hint]');
 check(Boolean(widthPrecedenceHint?.textContent)
     &&widthPrecedenceHint.textContent===root.querySelector('[data-visual-editor-extension-labels]').dataset.visualEditorWidthPrecedenceHintLabel,
     'the width HUD explains that its managed CSS draft takes precedence while saved Appearance settings remain unchanged');
 stage3Frame.style.maxWidth='none';stage3Frame.style.width='1600px';
 await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
 const shellWidths=()=>['.site-header','.site-main','.site-footer'].map(selector=>Number.parseFloat(stage3Frame.contentWindow.getComputedStyle(stage3Frame.contentDocument.querySelector(selector)).width));
 const effectiveWidthOutput=stage3Dialog.querySelector('[data-visual-editor-page-width-effective]');
 const reportedEffectiveWidth=()=>Number.parseFloat(effectiveWidthOutput.textContent.match(/([0-9]+(?:\\.[0-9]+)?)\\s*px$/)?.[1]||'NaN');
 const measuredMainWidth=()=>stage3Frame.contentDocument.querySelector('.site-main').getBoundingClientRect().width;
 check(shellWidths().every(width=>Math.abs(width-1440)<1),'the fixture begins with the actual wide Theme width on all three shells');
 const widthMode=stage3Dialog.querySelector('select[aria-label="Page width mode"]');
 widthMode.value='default';widthMode.dispatchEvent(new Event('change',{bubbles:true}));
 check(shellWidths().every(width=>Math.abs(width-1120)<1),'Default width overrides the more specific wide Theme cascade on header, main and footer');
 const defaultReportedWidth=reportedEffectiveWidth();
 const defaultMeasuredWidth=measuredMainWidth();
 check(Math.abs(defaultReportedWidth-defaultMeasuredWidth)<0.02,
     'effective-width readout matches the rendered main shell in Default mode (reported '
         +defaultReportedWidth+', measured '+defaultMeasuredWidth+')');
 check(defaultReportedWidth===1120,
     'the controlled Default width resolves to 1120 CSS pixels (reported '+defaultReportedWidth+')');
 check(effectiveWidthOutput.getAttribute('aria-live')==='polite'
     &&effectiveWidthOutput.textContent.startsWith('Effective content width:'),
     'the rendered width is announced through a localized polite status output');
 widthMode.value='custom';widthMode.dispatchEvent(new Event('change',{bubbles:true}));
 const widthSlider=stage3Dialog.querySelector('input[aria-label="Custom width"]');
 const widthNumber=stage3Dialog.querySelector('[data-visual-editor-page-width-number]');
 check(widthNumber.type==='number'&&widthNumber.min==='1024'&&widthNumber.max==='2048'&&widthNumber.step==='1'
     &&widthNumber.getAttribute('aria-label')==='Custom width in pixels',
     'Custom pixel input exposes its numeric bounds and localized label (type '+widthNumber.type+', min '+widthNumber.min
         +', max '+widthNumber.max+', step '+widthNumber.step+', label '+widthNumber.getAttribute('aria-label')+')');
 check(widthMode.value==='custom'&&widthNumber.value==='1600'&&!widthNumber.disabled,
     'Custom mode preserves the saved 1600-pixel preference after Default (mode '+widthMode.value+', value '+widthNumber.value
         +', disabled '+widthNumber.disabled+')');
 widthNumber.value='1120';widthNumber.dispatchEvent(new Event('change',{bubbles:true}));
 check(widthMode.value==='custom'&&widthNumber.value==='1120'&&!widthNumber.disabled
     &&shellWidths().every(width=>Math.abs(width-1120)<1),
     'an explicit Custom width of 1120 remains editable instead of being reclassified as Default');
 widthNumber.value='1440';widthNumber.dispatchEvent(new Event('change',{bubbles:true}));
 check(widthMode.value==='custom'&&widthNumber.value==='1440'&&!widthNumber.disabled
     &&shellWidths().every(width=>Math.abs(width-1440)<1),
     'an explicit Custom width of 1440 remains editable instead of being reclassified as Wide');
 widthSlider.value='1300';widthSlider.dispatchEvent(new Event('change',{bubbles:true}));
 check(shellWidths().every(width=>Math.abs(width-1300)<1)&&widthNumber.value==='1300'
     &&Math.abs(reportedEffectiveWidth()-measuredMainWidth())<0.02,
     'the slider synchronizes the editable value and moves all shells while the output reports rendered pixels');
 widthNumber.value='1275';widthNumber.dispatchEvent(new Event('change',{bubbles:true}));
 check(shellWidths().every(width=>Math.abs(width-1275)<1)&&widthSlider.value==='1275'
     &&Math.abs(reportedEffectiveWidth()-measuredMainWidth())<0.02,
     'the numeric field synchronizes the slider and applies the same managed width to all shells');
 widthNumber.value='';widthNumber.dispatchEvent(new Event('change',{bubbles:true}));
 check(widthNumber.value==='1275'&&Math.abs(reportedEffectiveWidth()-1275)<0.02,
     'an empty numeric value is rejected and restores the current effective custom value without a CSS edit');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Undo').click();
 check(shellWidths().every(width=>Math.abs(width-1300)<1),
     'the rejected empty value adds no history entry, so one Undo returns to the prior valid custom width');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Redo').click();
 check(shellWidths().every(width=>Math.abs(width-1275)<1),'Redo restores the last valid numeric custom width');
 widthMode.value='wide';widthMode.dispatchEvent(new Event('change',{bubbles:true}));
 widthMode.value='custom';widthMode.dispatchEvent(new Event('change',{bubbles:true}));
 check(widthMode.value==='custom'&&widthNumber.value==='1275'&&!widthNumber.disabled
     &&shellWidths().every(width=>Math.abs(width-1275)<1),
     'switching through a preset preserves the last explicit custom pixel preference');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Undo').click();
 check(widthMode.value==='wide'&&shellWidths().every(width=>Math.abs(width-1440)<1),
     'Undo restores the preset mode and CSS snapshot after returning from Custom');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Redo').click();
 check(widthMode.value==='custom'&&widthNumber.value==='1275'&&shellWidths().every(width=>Math.abs(width-1275)<1),
     'Redo restores both Custom mode and its retained numeric value');
 widthMode.value='full';widthMode.dispatchEvent(new Event('change',{bubbles:true}));
 check(shellWidths().every(width=>width>1500&&width<1580)
     &&Math.abs(reportedEffectiveWidth()-measuredMainWidth())<0.02,
     'Full width keeps the Theme viewport clamp and reports the rendered main-shell width');
 stage3Dialog.querySelector('[data-visual-editor-viewport="mobile"]').click();
 await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
 const mobileShellWidths=shellWidths();
 check(Math.abs(reportedEffectiveWidth()-measuredMainWidth())<0.02&&mobileShellWidths.every(width=>Math.abs(width-mobileShellWidths[1])<0.02),
     'the effective-width output follows the actual mobile iframe layout and all page shells remain aligned');
 stage3Dialog.querySelector('[data-visual-editor-viewport="desktop"]').click();
 stage3Frame.style.maxWidth='none';stage3Frame.style.width='1600px';
 window.dispatchEvent(new Event('resize'));
 await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Reset page width').click();
 check(shellWidths().every(width=>Math.abs(width-1440)<1)&&Math.abs(reportedEffectiveWidth()-measuredMainWidth())<0.02,
     'Reset removes the managed width group and restores the saved wide Theme width and its measured output');
 widthMode.value='custom';widthMode.dispatchEvent(new Event('change',{bubbles:true}));
 check(widthMode.value==='custom'&&widthNumber.value==='1600'&&!widthNumber.disabled,
     'Reset restores the saved Theme custom-pixel preference for the next Custom selection');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Reset page width').click();
 stage3Dialog.querySelector('[data-visual-editor-reset-session]').click();
 check(widthMode.value==='wide'&&widthNumber.value==='1600'&&text.value===originalDraft
     &&shellWidths().every(width=>Math.abs(width-1440)<1),
     'Reset session restores the exact entry CSS, preset mode, and custom-width preference');
 check(text.value===originalDraft,'page-width previews remain an unsaved visual draft until Apply to CSS editor');
 const layer=stage3Frame.contentDocument.querySelector('.theme-background-image');
 const backgroundLayerOpacity=stage3Dialog.querySelector('input[aria-label="Background opacity"]');
 backgroundLayerOpacity.value='0.25';backgroundLayerOpacity.dispatchEvent(new Event('input',{bubbles:true}));
 check(stage3Frame.contentWindow.getComputedStyle(layer).opacity==='0.25','the range input updates the real cross-realm Theme image layer before its change event');
 backgroundLayerOpacity.dispatchEvent(new Event('change',{bubbles:true}));
 check(stage3Frame.contentWindow.getComputedStyle(layer).opacity==='0.25','opacity edits only the separate Theme background image layer');
 check(stage3Frame.contentWindow.getComputedStyle(stage3Frame.contentDocument.querySelector('#hero h1')).opacity==='1','background opacity never fades page content');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Reset background opacity').click();
 check(stage3Frame.contentWindow.getComputedStyle(layer).opacity==='0.65','opacity reset restores the saved Theme baseline');
 const noImageStyle=stage3Frame.contentDocument.createElement('style');
 noImageStyle.textContent='.theme-background-image{background-image:none}';
 stage3Frame.contentDocument.head.append(noImageStyle);
 check(stage3Frame.contentWindow.getComputedStyle(layer).backgroundImage==='none','a fresh Theme background layer can have no saved image');
 const firstImage=new File(['fixture image bytes'],'first.png',{type:'image/png'});
 const imageTransfer=new DataTransfer();imageTransfer.items.add(firstImage);backgroundInput.files=imageTransfer.files;
 backgroundInput.dispatchEvent(new Event('change',{bubbles:true}));
 check(stage3Frame.contentWindow.getComputedStyle(layer).backgroundImage.includes('blob:'),'a pending File previews on the real background layer using an object URL');
 check(!text.value.includes('blob:')&&root.querySelector('[data-visual-editor-background-status]').textContent.includes('Save explicitly')
     &&root.querySelector('[data-visual-editor-background-operation]').value==='replace'
     &&root.querySelector('[data-visual-editor-pending-background-preview]').src.includes('blob:'),'the pending image remains separate from CSS and shows its explicit replace operation and safe preview');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Undo').click();
 check(backgroundInput.files.length===0&&root.querySelector('[data-visual-editor-background-operation]').value==='keep'
     &&stage3Frame.contentWindow.getComputedStyle(layer).backgroundImage==='none','Undo removes the pending image operation and preserves an empty server background layer');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Redo').click();
 check(backgroundInput.files[0]?.name==='first.png'&&root.querySelector('[data-visual-editor-background-operation]').value==='replace'
     &&stage3Frame.contentWindow.getComputedStyle(layer).backgroundImage.includes('blob:'),'Redo restores the same pending replace operation and File preview without uploading it');
 const removeBackgroundButton=stage3Dialog.querySelector('[data-visual-editor-remove-background]');
 removeBackgroundButton.click();
 check(root.querySelector('[data-visual-editor-background-operation]').value==='remove'&&backgroundInput.files.length===0
     &&stage3Frame.contentWindow.getComputedStyle(layer).backgroundImage==='none'
     &&text.value===originalDraft,'Remove stages a reversible global operation and changes only the isolated preview');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Undo').click();
 check(root.querySelector('[data-visual-editor-background-operation]').value==='replace'&&backgroundInput.files[0]?.name==='first.png'
     &&stage3Frame.contentWindow.getComputedStyle(layer).backgroundImage.includes('blob:'),'Undo restores the prior replacement File when it follows a staged removal');
 const mixedHistoryUndo=[...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Undo');
 const mixedHistoryRedo=[...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Redo');
 const mixedHistoryCss=text.value;
 const mixedHistoryCssRevision=root.querySelector('[data-css-override-revision]').value;
 const mixedHistoryBackgroundRevision=root.querySelector('[data-visual-editor-background-revision]').value;
 const mixedHistoryMatches=(operation,fileName,opacity)=>root.querySelector('[data-visual-editor-background-operation]').value===operation
     &&(backgroundInput.files[0]?.name||'')===fileName
     &&stage3Frame.contentWindow.getComputedStyle(layer).opacity===opacity;
 backgroundLayerOpacity.value='0.4';backgroundLayerOpacity.dispatchEvent(new Event('input',{bubbles:true}));
 backgroundLayerOpacity.dispatchEvent(new Event('change',{bubbles:true}));
 const mixedReplacement=new File(['mixed replacement bytes'],'mixed-replacement.webp',{type:'image/webp'});
 const mixedReplacementTransfer=new DataTransfer();mixedReplacementTransfer.items.add(mixedReplacement);
 backgroundInput.files=mixedReplacementTransfer.files;backgroundInput.dispatchEvent(new Event('change',{bubbles:true}));
 backgroundLayerOpacity.value='0.3';backgroundLayerOpacity.dispatchEvent(new Event('input',{bubbles:true}));
 backgroundLayerOpacity.dispatchEvent(new Event('change',{bubbles:true}));
 check(mixedHistoryMatches('replace','mixed-replacement.webp','0.3'),'mixed history records CSS edit H1, File replacement H2, and later CSS edit H3');
 mixedHistoryUndo.click();
 check(mixedHistoryMatches('replace','mixed-replacement.webp','0.4')&&!mixedHistoryRedo.disabled,
     'Undo H3 restores the exact H2 File and CSS snapshot while retaining H3 for Redo');
 mixedHistoryUndo.click();
 check(mixedHistoryMatches('replace','first.png','0.4')&&!mixedHistoryRedo.disabled
     &&stage3Frame.contentWindow.getComputedStyle(layer).backgroundImage.includes('blob:'),
     'Undo H2 across the File change restores File A while keeping both forward snapshots available');
 mixedHistoryUndo.click();
 check(mixedHistoryMatches('replace','first.png','0.65'),
     'a further Undo reaches the exact baseline before the mixed sequence');
 mixedHistoryRedo.click();
 check(mixedHistoryMatches('replace','first.png','0.4'),
     'the first Redo restores the CSS action before File replacement');
 mixedHistoryRedo.click();
 check(mixedHistoryMatches('replace','mixed-replacement.webp','0.4')&&!mixedHistoryRedo.disabled
     &&stage3Frame.contentWindow.getComputedStyle(layer).backgroundImage.includes('blob:'),
     'Redo H2 restores the exact File B and leaves the H3 CSS snapshot available');
 mixedHistoryRedo.click();
 check(mixedHistoryMatches('replace','mixed-replacement.webp','0.3'),
     'Redo H3 restores its CSS snapshot after the File replacement branch');
 mixedHistoryUndo.click();mixedHistoryUndo.click();mixedHistoryUndo.click();
 check(mixedHistoryMatches('replace','first.png','0.65')&&text.value===mixedHistoryCss
     &&root.querySelector('[data-css-override-revision]').value===mixedHistoryCssRevision
     &&root.querySelector('[data-visual-editor-background-revision]').value===mixedHistoryBackgroundRevision,
     'restoring the mixed sequence leaves the entry File, CSS bytes, and last-confirmed revisions intact');
 noImageStyle.remove();
 stage3Frame.contentDocument.querySelector('#gallery-link').click();
 const stage3GalleryFollow=stage3Dialog.querySelector('[data-visual-editor-follow-link]');
 check(Boolean(stage3GalleryFollow&&!stage3GalleryFollow.hidden),'the selected gallery anchor exposes explicit protected navigation');
 const stage3GalleryLoad=waitForFrameLoad(stage3Frame,'Follow Link to the gallery');
 stage3GalleryFollow.click();
 await stage3GalleryLoad;
 check(stage3Frame.contentDocument?.title==='Fixture gallery','explicit Follow commits the gallery document before checking its canonical background');
 const galleryBackground=stage3Frame.contentDocument.querySelector('.theme-background-image');
 check(galleryBackground.dataset.themeBackgroundVisualEditorTarget==='none'
     &&galleryBackground.dataset.themeBackgroundVisibleOwner==='gallery_override'
     &&galleryBackground.dataset.themeBackgroundVisibleMode==='existing'
     &&galleryBackground.style.backgroundImage===''&&stage3Frame.contentWindow.getComputedStyle(galleryBackground).backgroundImage.includes('linear-gradient')
     &&stage3Dialog.querySelector('[data-visual-editor-remove-background]').disabled
     &&stage3Dialog.querySelector('[data-visual-editor-background-choose]').disabled
     &&root.querySelector('[data-visual-editor-background-source-review]').textContent.includes('Gallery-specific background')
     &&root.querySelector('[data-visual-editor-background-source-review]').textContent.includes('Gallery-selected existing image')
     &&root.querySelector('[data-visual-editor-background-status]').textContent.includes('open Home'),
 'gallery-specific existing background remains canonical and the global operation controls explain that Home owns the global preview');
 const stage3HomeLoad=waitForFrameLoad(stage3Frame,'return to Home');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Home').click();
 await stage3HomeLoad;
 check(stage3Frame.contentDocument?.title==='Fixture home','Home commits the Theme document before checking the pending replacement preview');
 check(stage3Frame.contentWindow.getComputedStyle(stage3Frame.contentDocument.querySelector('.theme-background-image')).backgroundImage.includes('blob:'),
     'returning to Home reapplies the still-pending global replacement to the actual Theme image layer');
 const semanticCard=stage3Frame.contentDocument.querySelector('#fixture-gallery-card');
 semanticCard.click();
 const semanticInspector=stage3Dialog.querySelector('.theme-visual-editor-inspector');
 const semanticSelectedTarget=stage3Frame.contentDocument.querySelector('[data-visual-selected]');
 check(Boolean(semanticSelectedTarget===semanticCard&&semanticSelectedTarget.isConnected),
     'the iframe event selects the connected semantic gallery card');
 const semanticTargetScopeLabel=root.dataset.visualEditorTargetScopeLabel;
 const semanticTargetScope=Array.from(semanticInspector.querySelectorAll('select'))
     .find(select=>select.getAttribute('aria-label')===semanticTargetScopeLabel);
 check(Boolean(semanticTargetScope),'the selected card exposes its accessible target-scope control');
 const semanticOnly=semanticTargetScope.querySelector('option[value="only"]');
 check(Boolean(semanticOnly&&!semanticOnly.disabled),
     'the persisted gallery component enables its bounded Only-this option');
 semanticTargetScope.value='only';
 semanticTargetScope.dispatchEvent(new Event('change',{bubbles:true}));
 check(semanticTargetScope.value==='only','the semantic target-scope control accepts Only-this');
 const semanticSelector=semanticInspector.querySelector('code');
 check(semanticSelector.textContent==='body.public-page article.gallery-card[data-gallery-id="17"]',
     'Only-this persists the page-scoped semantic gallery component selector');
 const hero=stage3Frame.contentDocument.querySelector('#hero');
 hero.click();
 const heroInspector=stage3Dialog.querySelector('.theme-visual-editor-inspector');
 check(heroInspector.querySelector('strong').textContent==='section#hero.hero','actual public Theme hero markup receives a bounded hero profile');
 const heroTargetScope=Array.from(heroInspector.querySelectorAll('select'))
     .find(select=>select.getAttribute('aria-label')===root.dataset.visualEditorTargetScopeLabel);
 const onlyScope=heroTargetScope?.querySelector('option[value="only"]');
 check(Boolean(onlyScope?.disabled),'route-local hero IDs do not enable a misleading sitewide Only this target');
 [...heroInspector.querySelectorAll('button')].find(button=>button.textContent==='Style element').click();
 check(heroInspector.querySelector('.theme-visual-editor-properties > strong')?.textContent==='Hero','hero inspection exposes the translated hero property profile');
 const heroFields=[...heroInspector.querySelectorAll('fieldset')];
 check(heroFields.some(field=>field.querySelector('legend')?.textContent==='Backdrop filter')
     &&heroFields.some(field=>field.querySelector('legend')?.textContent==='WebKit backdrop filter'),
     'the actual Theme hero profile exposes both bounded backdrop-filter declarations');
 const backgroundField=heroFields.find(field=>field.querySelector('legend')?.textContent==='background-color');
 const colorPicker=backgroundField.querySelector('input[type="color"]');
 const opacity=backgroundField.querySelector('input[type="range"]');
 colorPicker.value='#336699';
 opacity.value='50';
 colorPicker.dispatchEvent(new Event('change',{bubbles:true}));
 check(stage3Frame.contentWindow.getComputedStyle(hero).backgroundColor==='rgba(51, 102, 153, 0.5)','color and alpha controls update the real Theme hero background');
 check(stage3Frame.contentWindow.getComputedStyle(hero,'::before').backgroundImage==='none','the managed pseudo companion clears the actual Theme gradient overlay');
 check(stage3Frame.contentWindow.getComputedStyle(hero.querySelector('h1')).opacity==='1','translucent hero color leaves child text opacity unchanged');
 const undo=[...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Undo');
 const redo=[...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Redo');
 undo.click();
 check(stage3Frame.contentWindow.getComputedStyle(hero,'::before').backgroundImage.includes('linear-gradient'),'undo restores the Theme-owned pseudo gradient');
 redo.click();
 check(stage3Frame.contentWindow.getComputedStyle(hero,'::before').backgroundImage==='none','redo reapplies the paired hero background edit');
 const resizeHandles=[...stage3Dialog.querySelectorAll('.theme-visual-css-resize-handle')];
 const bottomResize=resizeHandles.find(handle=>handle.dataset.resizeEdge==='bottom');
 check(resizeHandles.length===2&&bottomResize?.getAttribute('aria-label')==='Resize from bottom edge','hero selection exposes only localized top and bottom resize handles');
 const originalTextareaDuringResize=text.value;
 const commitResize=await new Promise(resolve=>{
  window.__visualResizeCompletion=resolve;
  const rect=bottomResize.getBoundingClientRect();
  window.__visualResizePublishRequest({phase:'commit',x:rect.left+rect.width/2,y:rect.top+rect.height/2,delta:36});
 });
 check(commitResize.previewed,'native pointer movement renders a transient resize stylesheet before release');
 const committedHeroMinHeight=stage3Frame.contentWindow.getComputedStyle(hero).minHeight;
 check(committedHeroMinHeight.endsWith('px')&&Number.parseFloat(committedHeroMinHeight)>64,'pointer release commits the bounded hero minimum height');
 check(text.value===originalTextareaDuringResize,'resize edits remain in the managed draft until Apply to CSS editor and exit');
 const resizeUndo=[...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Undo');
 const resizeRedo=[...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Redo');
 resizeUndo.click();
 check(stage3Frame.contentWindow.getComputedStyle(hero).minHeight!==committedHeroMinHeight,'one Undo removes the complete resize as one history operation');
 resizeRedo.click();
 check(stage3Frame.contentWindow.getComputedStyle(hero).minHeight===committedHeroMinHeight,'one Redo restores the committed resize operation');
 const escapeResize=await new Promise(resolve=>{
  window.__visualResizeCompletion=resolve;
  const handle=stage3Dialog.querySelector('.theme-visual-css-resize-handle[data-resize-edge="bottom"]');
  const rect=handle.getBoundingClientRect();
  window.__visualResizePublishRequest({phase:'escape',x:rect.left+rect.width/2,y:rect.top+rect.height/2,delta:44});
 });
 check(escapeResize.previewed&&escapeResize.cancelled,'Escape cancels a live native resize gesture');
 check(escapeResize.repositioned,'Escape returns the resize handles to the restored target geometry');
 check(stage3Frame.contentWindow.getComputedStyle(hero).minHeight===committedHeroMinHeight,'Escape restores the last committed layout exactly');
 check(text.value===originalTextareaDuringResize,'cancelled resize never changes the authoritative CSS textarea');
 const resizeOverlay=stage3Dialog.querySelector('.theme-visual-editor-resize-overlay');
 hero.querySelector('h1').click();
 check(resizeOverlay?.isConnected===true&&getComputedStyle(resizeOverlay).pointerEvents==='none'&&resizeOverlay.style.pointerEvents==='none','selection cleanup restores the resize overlay hit-testing policy');
 check(stage3Dialog.querySelectorAll('.theme-visual-css-resize-handle').length===0,'selection cleanup removes resize handles from unsupported inline text');
 hero.click();
 [...heroInspector.querySelectorAll('button')].find(button=>button.textContent==='Style element').click();
 const scopeSelect=Array.from(heroInspector.querySelectorAll('select'))
     .find(select=>select.getAttribute('aria-label')===root.dataset.visualEditorScopeLabel);
 check(Boolean(scopeSelect?.isConnected),'the responsive-scope check uses the live CSS scope control');
 scopeSelect.value='responsive:tablet';
 scopeSelect.dispatchEvent(new Event('change',{bubbles:true}));
 const textField=[...heroInspector.querySelectorAll('fieldset')].find(field=>field.querySelector('legend')?.textContent==='color');
 const textValue=textField.querySelector('input[type="text"]');
 textValue.value='#123456';
 textValue.dispatchEvent(new Event('change',{bubbles:true}));
 check(stage3Frame.contentWindow.getComputedStyle(hero).color==='rgb(17, 17, 17)','responsive scope remains inactive at the desktop preview width');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Mobile').click();
 check(stage3Frame.contentWindow.getComputedStyle(stage3Frame.contentDocument.querySelector('#hero')).color==='rgb(18, 52, 86)','an explicitly selected tablet scope applies at the mobile viewport width');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Desktop').click();
 check(stage3Frame.contentWindow.getComputedStyle(stage3Frame.contentDocument.querySelector('#hero')).color==='rgb(17, 17, 17)','changing viewport preset does not alter or broaden the explicit responsive scope');
 scopeSelect.value='site';scopeSelect.dispatchEvent(new Event('change',{bubbles:true}));
 check(scopeSelect.value==='site','the following base-profile checks explicitly return to site scope after responsive-scope coverage');
 const cssBytesBeforeStage3Profiles=text.value;
 const headingTarget=stage3Frame.contentDocument.querySelector('#hero-title');
 headingTarget.click();
 const headingInspector=stage3Dialog.querySelector('.theme-visual-editor-inspector');
 [...headingInspector.querySelectorAll('button')].find(button=>button.textContent==='Style element').click();
 const headingDecoration=[...headingInspector.querySelectorAll('fieldset')]
     .find(field=>field.querySelector('legend')?.textContent==='Text decoration');
 const headingDecorationSelect=headingDecoration?.querySelector('select');
 check(headingDecorationSelect?.getAttribute('aria-label')==='Text decoration · CSS value'
     &&[...headingDecorationSelect.options].map(option=>option.value).join('|')==='|none|underline|overline|line-through',
     'heading controls expose a localized, closed text-decoration choice');
 headingDecorationSelect.value='underline';headingDecorationSelect.dispatchEvent(new Event('change',{bubbles:true}));
 check(stage3Frame.contentWindow.getComputedStyle(headingTarget).textDecorationLine==='underline'
     &&text.value===cssBytesBeforeStage3Profiles,'text decoration previews in the iframe and remains a CSS draft');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Undo').click();
 check(stage3Frame.contentWindow.getComputedStyle(headingTarget).textDecorationLine==='none',
     'Undo removes the text-decoration draft from the selected heading');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Redo').click();
 check(stage3Frame.contentWindow.getComputedStyle(headingTarget).textDecorationLine==='underline',
     'Redo restores the text-decoration declaration');
 [...headingInspector.querySelectorAll('fieldset')].find(field=>field.querySelector('legend')?.textContent==='Text decoration')
     .querySelector('button').click();
 check(stage3Frame.contentWindow.getComputedStyle(headingTarget).textDecorationLine==='none'
     &&text.value===cssBytesBeforeStage3Profiles,'reset removes only managed text decoration and leaves the CSS textarea untouched');
 const paragraphTarget=stage3Frame.contentDocument.querySelector('#draft-target');
 paragraphTarget.click();
 const paragraphInspector=stage3Dialog.querySelector('.theme-visual-editor-inspector');
 [...paragraphInspector.querySelectorAll('button')].find(button=>button.textContent==='Style element').click();
 let paragraphDecoration=[...paragraphInspector.querySelectorAll('fieldset')]
     .find(field=>field.querySelector('legend')?.textContent==='Text decoration')?.querySelector('select');
 check(Boolean(paragraphDecoration),'paragraph controls expose the same localized text-decoration choice as headings');
 paragraphDecoration.value='overline';paragraphDecoration.dispatchEvent(new Event('change',{bubbles:true}));
 check(stage3Frame.contentWindow.getComputedStyle(paragraphTarget).textDecorationLine==='overline'
     &&text.value===cssBytesBeforeStage3Profiles,'paragraph text decoration previews as an unsaved managed CSS draft');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Undo').click();
 check(stage3Frame.contentWindow.getComputedStyle(paragraphTarget).textDecorationLine==='none',
     'Undo removes the paragraph text-decoration declaration');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Redo').click();
 check(stage3Frame.contentWindow.getComputedStyle(paragraphTarget).textDecorationLine==='overline',
     'Redo restores the paragraph text-decoration declaration');
 paragraphDecoration=[...paragraphInspector.querySelectorAll('fieldset')]
     .find(field=>field.querySelector('legend')?.textContent==='Text decoration')?.querySelector('select');
 paragraphDecoration.value='';paragraphDecoration.dispatchEvent(new Event('change',{bubbles:true}));
 check(stage3Frame.contentWindow.getComputedStyle(paragraphTarget).textDecorationLine==='none'
     &&text.value===cssBytesBeforeStage3Profiles,'the no-override paragraph choice resets only its managed decoration');
 const linkTarget=stage3Frame.contentDocument.querySelector('#gallery-link');
 const linkPaddingBefore=stage3Frame.contentWindow.getComputedStyle(linkTarget).padding;
 linkTarget.click();
 const linkInspector=stage3Dialog.querySelector('.theme-visual-editor-inspector');
 const linkTargetScope=Array.from(linkInspector.querySelectorAll('select'))
     .find(select=>select.getAttribute('aria-label')===root.dataset.visualEditorTargetScopeLabel);
 check(linkTargetScope?.querySelector('option[value="only"]')?.disabled===true,
     'route-local link identity does not offer an unsafe Only-this selector');
 [...linkInspector.querySelectorAll('button')].find(button=>button.textContent==='Style element').click();
 check(linkInspector.querySelector('.theme-visual-editor-properties > strong')?.textContent==='Links and buttons',
     'anchors receive the dedicated localized link and button profile');
 const linkFieldNames=[...linkInspector.querySelectorAll('fieldset legend')].map(legend=>legend.textContent);
 check(['background-color','color','font-size','font-weight','line-height','text-align','Text decoration',
     'border-radius','border-width','border-style','border-color','box-shadow','padding'].every(name=>linkFieldNames.includes(name)),
     'link controls include alpha-capable background and foreground, typography, border, shadow, and padding properties');
 const linkBackgroundField=[...linkInspector.querySelectorAll('fieldset')]
     .find(field=>field.querySelector('legend')?.textContent==='background-color');
 linkBackgroundField.querySelector('input[type="color"]').value='#336699';
 linkBackgroundField.querySelector('input[type="range"]').value='40';
 linkBackgroundField.querySelector('input[type="color"]').dispatchEvent(new Event('change',{bubbles:true}));
 check(stage3Frame.contentWindow.getComputedStyle(linkTarget).backgroundColor==='rgba(51, 102, 153, 0.4)'
     &&text.value===cssBytesBeforeStage3Profiles,'link background alpha previews without saving the textarea');
 let linkRadiusField=[...linkInspector.querySelectorAll('fieldset')]
     .find(field=>field.querySelector('legend')?.textContent==='border-radius');
 linkRadiusField.querySelector('input[type="text"]').value='8px';
 linkRadiusField.querySelector('input[type="text"]').dispatchEvent(new Event('change',{bubbles:true}));
 check(stage3Frame.contentWindow.getComputedStyle(linkTarget).borderTopLeftRadius==='8px',
     'link border radius edits the selected public anchor');
 let linkPaddingField=[...linkInspector.querySelectorAll('fieldset')]
     .find(field=>field.querySelector('legend')?.textContent==='padding');
 linkPaddingField.querySelector('input[type="text"]').value='1rem 2rem';
 linkPaddingField.querySelector('input[type="text"]').dispatchEvent(new Event('change',{bubbles:true}));
 check(stage3Frame.contentWindow.getComputedStyle(linkTarget).padding==='16px 32px',
     'link padding edits the selected public anchor');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Undo').click();
 check(stage3Frame.contentWindow.getComputedStyle(linkTarget).padding===linkPaddingBefore,
     'Undo removes only the most recent link padding declaration');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Redo').click();
 linkPaddingField=[...linkInspector.querySelectorAll('fieldset')].find(field=>field.querySelector('legend')?.textContent==='padding');
 check(stage3Frame.contentWindow.getComputedStyle(linkTarget).padding==='16px 32px',
     'Redo restores the link padding declaration');
 linkPaddingField.querySelector('button').click();
 check(stage3Frame.contentWindow.getComputedStyle(linkTarget).padding===linkPaddingBefore
     &&stage3Frame.contentWindow.getComputedStyle(linkTarget).backgroundColor==='rgba(51, 102, 153, 0.4)',
     'Reset removes only the managed padding and preserves the link color and radius edits');
 const buttonTarget=stage3Frame.contentDocument.querySelector('#fixture-action-button');
 buttonTarget.click();
 const buttonInspector=stage3Dialog.querySelector('.theme-visual-editor-inspector');
 [...buttonInspector.querySelectorAll('button')].find(button=>button.textContent==='Style element').click();
 check(buttonInspector.querySelector('.theme-visual-editor-properties > strong')?.textContent==='Links and buttons'
     &&[...buttonInspector.querySelectorAll('fieldset legend')].some(legend=>legend.textContent==='background-color')
     &&[...buttonInspector.querySelectorAll('fieldset legend')].some(legend=>legend.textContent==='padding'),
     'buttons receive the same bounded background and spacing profile as anchors');
 const headerTarget=stage3Frame.contentDocument.querySelector('.site-header');
 const headerBackdropBefore=stage3Frame.contentWindow.getComputedStyle(headerTarget).backdropFilter;
 headerTarget.click();
 const headerInspector=stage3Dialog.querySelector('.theme-visual-editor-inspector');
 const headerScopes=Array.from(headerInspector.querySelectorAll('select'));
 const headerPropertyScope=headerScopes.find(select=>select.getAttribute('aria-label')===root.dataset.visualEditorScopeLabel);
 const headerTargetScope=headerScopes.find(select=>select.getAttribute('aria-label')===root.dataset.visualEditorTargetScopeLabel);
 const headerOnlyOption=headerTargetScope?.querySelector('option[value="only"]');
 check(headerOnlyOption&&!headerOnlyOption.disabled,
     'the canonical Theme header identity supports the existing Only-this scope');
 check(Boolean(headerPropertyScope?.isConnected&&headerTargetScope?.isConnected&&headerPropertyScope!==headerTargetScope),
     'header CSS scope and element target scope are distinct live controls');
 headerPropertyScope.value='site';headerPropertyScope.dispatchEvent(new Event('change',{bubbles:true}));
 check(headerPropertyScope.value==='site','the header profile is explicitly authored at all viewports');
 headerTargetScope.value='only';headerTargetScope.dispatchEvent(new Event('change',{bubbles:true}));
 check(headerTargetScope.value==='only'&&headerPropertyScope.value==='site'
     &&headerInspector.querySelector('code').textContent==='body.public-page header.site-header',
     'Only-this targets the canonical public header selector without changing the HUD-owned width axis');
 [...headerInspector.querySelectorAll('button')].find(button=>button.textContent==='Style element').click();
 const headerFields=[...headerInspector.querySelectorAll('fieldset')];
 check(headerInspector.querySelector('.theme-visual-editor-properties > strong')?.textContent==='Header'
     &&headerFields.some(field=>field.querySelector('legend')?.textContent==='Backdrop filter')
     &&headerFields.some(field=>field.querySelector('legend')?.textContent==='WebKit backdrop filter')
     &&!headerFields.some(field=>field.querySelector('legend')?.textContent==='width'),
     'the header profile exposes both bounded backdrop declarations and leaves horizontal sizing to the HUD');
 const backdropAuthoredValues=()=>{
  const draftStyle=stage3Frame.contentDocument.querySelector('[data-theme-visual-editor-draft]');
  const draftText=draftStyle?.textContent||'';
  const selector=headerInspector.querySelector('code')?.textContent||'';
  const start=draftText.indexOf(selector+' {');
  const end=start<0?-1:draftText.indexOf('}',start);
  const ruleText=end>start?draftText.slice(start,end):'';
  const propertyValue=property=>{
   const line=ruleText.split(String.fromCharCode(10)).map(value=>value.trim()).find(value=>{
    const colon=value.indexOf(':');
    return colon>0&&value.slice(0,colon).trim()===property;
   });
   if(!line)return '';
   const value=line.slice(line.indexOf(':')+1).trim();
   return value.endsWith(';')?value.slice(0,-1).trim():value;
  };
  return {standard:propertyValue('backdrop-filter'),webkit:propertyValue('-webkit-backdrop-filter')};
 };
 const requestedBackdrop='blur(8px) saturate(1.2)';
 const prefixedBackdropSupported=stage3Frame.contentWindow.CSS.supports('-webkit-backdrop-filter','blur(8px)');
 const prefixedBackdropEffectMatches=()=>{
  const computed=stage3Frame.contentWindow.getComputedStyle(headerTarget);
  return prefixedBackdropSupported
      ?computed.getPropertyValue('-webkit-backdrop-filter')===requestedBackdrop||computed.backdropFilter===requestedBackdrop
      :computed.backdropFilter===headerBackdropBefore;
 };
 const standardBackdropField=headerFields.find(field=>field.querySelector('legend')?.textContent==='Backdrop filter');
 standardBackdropField.querySelector('input[type="text"]').value=requestedBackdrop;
 standardBackdropField.querySelector('input[type="text"]').dispatchEvent(new Event('change',{bubbles:true}));
 const standardBackdropDraftStyle=stage3Frame.contentDocument.querySelector('[data-theme-visual-editor-draft]');
 const standardBackdropSelector=headerInspector.querySelector('code')?.textContent||'';
 const standardBackdropRule=[...(standardBackdropDraftStyle?.sheet?.cssRules||[])]
     .find(rule=>typeof rule.selectorText==='string'&&rule.selectorText===standardBackdropSelector
         &&typeof rule.style?.getPropertyValue==='function'&&rule.style.getPropertyValue('backdrop-filter')!=='');
 const standardBackdropComputed=stage3Frame.contentWindow.getComputedStyle(headerTarget);
 const standardBackdropValues=backdropAuthoredValues();
 const standardBackdropDiagnostic={siteScope:headerPropertyScope.value==='site',propertyScopeConnected:headerPropertyScope.isConnected,
     targetScopeOnly:headerTargetScope.value==='only',canonicalSelector:standardBackdropSelector==='body.public-page header.site-header',
     draftStyleConnected:Boolean(standardBackdropDraftStyle?.isConnected),
     authoredStandardValueMatches:standardBackdropValues.standard===requestedBackdrop,
     authoredWebkitValueMatches:standardBackdropValues.webkit===requestedBackdrop,
     cssomStandardRulePresent:Boolean(standardBackdropRule),
     cssomStandardValue:String(standardBackdropRule?.style?.getPropertyValue('backdrop-filter')||'').slice(0,96),
     cssomWebkitValue:String(standardBackdropRule?.style?.getPropertyValue('-webkit-backdrop-filter')||'').slice(0,96),
     camelCaseComputed:String(standardBackdropComputed.backdropFilter||'').slice(0,96),
     standardComputed:standardBackdropComputed.getPropertyValue('backdrop-filter').slice(0,96),
     webkitComputed:standardBackdropComputed.getPropertyValue('-webkit-backdrop-filter').slice(0,96),textareaUnchanged:text.value===cssBytesBeforeStage3Profiles};
 check(stage3Frame.contentWindow.getComputedStyle(headerTarget).backdropFilter===requestedBackdrop
     &&standardBackdropValues.standard===requestedBackdrop&&standardBackdropValues.webkit === ''
     &&text.value===cssBytesBeforeStage3Profiles,'the standard backdrop filter previews a bounded composed value without saving: '+JSON.stringify(standardBackdropDiagnostic));
 const webkitBackdropField=[...headerInspector.querySelectorAll('fieldset')]
     .find(field=>field.querySelector('legend')?.textContent==='WebKit backdrop filter');
 webkitBackdropField.querySelector('input[type="text"]').value=requestedBackdrop;
 webkitBackdropField.querySelector('input[type="text"]').dispatchEvent(new Event('change',{bubbles:true}));
 const combinedBackdropValues=backdropAuthoredValues();
 check(combinedBackdropValues.standard===requestedBackdrop&&combinedBackdropValues.webkit===requestedBackdrop
     &&stage3Frame.contentWindow.getComputedStyle(headerTarget).backdropFilter===requestedBackdrop
     &&text.value===cssBytesBeforeStage3Profiles,
     'the prefixed fallback is authored independently while the browser applies its supported filter without saving');
 const standardBackdropReset=[...headerInspector.querySelectorAll('fieldset')]
     .find(field=>field.querySelector('legend')?.textContent==='Backdrop filter');
 standardBackdropReset.querySelector('button').click();
 const standardResetBackdropValues=backdropAuthoredValues();
 check(standardResetBackdropValues.standard===''&&standardResetBackdropValues.webkit===requestedBackdrop
     &&prefixedBackdropEffectMatches()&&text.value===cssBytesBeforeStage3Profiles,
     'Reset removes only the standard declaration and retains the independently authored prefixed fallback');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Undo').click();
 const standardUndoBackdropValues=backdropAuthoredValues();
 check(standardUndoBackdropValues.standard===requestedBackdrop&&standardUndoBackdropValues.webkit===requestedBackdrop
     &&stage3Frame.contentWindow.getComputedStyle(headerTarget).backdropFilter===requestedBackdrop
     &&text.value===cssBytesBeforeStage3Profiles,
     'Undo restores the exact standard backdrop declaration and its effective filter');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Redo').click();
 const standardRedoBackdropValues=backdropAuthoredValues();
 check(standardRedoBackdropValues.standard===''&&standardRedoBackdropValues.webkit===requestedBackdrop
     &&prefixedBackdropEffectMatches()&&text.value===cssBytesBeforeStage3Profiles,
     'Redo restores the single-property standard reset without saving CSS');
 const webkitBackdropReset=[...headerInspector.querySelectorAll('fieldset')]
     .find(field=>field.querySelector('legend')?.textContent==='WebKit backdrop filter');
 webkitBackdropReset.querySelector('button').click();
 const webkitResetBackdropValues=backdropAuthoredValues();
 check(webkitResetBackdropValues.standard===''&&webkitResetBackdropValues.webkit === ''
     &&stage3Frame.contentWindow.getComputedStyle(headerTarget).backdropFilter===headerBackdropBefore
     &&text.value===cssBytesBeforeStage3Profiles,
     'Reset removes the remaining prefixed declaration while the standard declaration stays reset');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Undo').click();
 const webkitUndoBackdropValues=backdropAuthoredValues();
 check(webkitUndoBackdropValues.standard===''&&webkitUndoBackdropValues.webkit===requestedBackdrop
     &&prefixedBackdropEffectMatches()&&text.value===cssBytesBeforeStage3Profiles,
     'Undo restores only the authored prefixed fallback and its browser-supported effect');
 [...stage3Dialog.querySelectorAll('button')].find(button=>button.textContent==='Redo').click();
 const webkitRedoBackdropValues=backdropAuthoredValues();
 check(webkitRedoBackdropValues.standard===''&&webkitRedoBackdropValues.webkit === ''
     &&stage3Frame.contentWindow.getComputedStyle(headerTarget).backdropFilter===headerBackdropBefore
     &&text.value===cssBytesBeforeStage3Profiles,'Redo restores the isolated prefixed reset without saving CSS');
 stage3Dialog.querySelector('[data-visual-editor-apply-exit]').click();
 check(text.value.startsWith(originalDraft)&&text.value.includes('PHP Gallery managed visual CSS'),'Apply merges the visual block while preserving the exact manual CSS prefix');
 check(!text.value.includes('blob:')&&backgroundInput.files[0]?.name==='first.png','Apply keeps the File pending for explicit Save and never serializes its object URL');
 const backgroundStatus=document.querySelector('[data-visual-editor-background-status]');
 check(!backgroundInput.classList.contains('theme-visual-editor-file-input')&&backgroundInput.getClientRects().length>0
     &&backgroundStatus.textContent.includes('first.png'),'Apply restores the native file chooser and reviews the pending filename beside the CSS');
 check(!document.querySelector('.theme-visual-css-resize-handle'),'workspace exit removes every parent-owned resize handle');
 const appliedCss=text.value;

 stage3Launch.click();
 const reopenDialog=document.querySelector('.theme-visual-editor-workspace');
 const reopenFrame=reopenDialog.querySelector('iframe');
 await waitForFrame(reopenFrame);
 check(reopenFrame.contentWindow.getComputedStyle(reopenFrame.contentDocument.querySelector('.theme-background-image')).backgroundImage.includes('blob:'),'reopening recreates the pending preview from the retained File');
 check(backgroundInput.classList.contains('theme-visual-editor-file-input')&&backgroundInput.files[0]?.name==='first.png'
     &&backgroundStatus.textContent.includes('first.png'),'re-entry keeps the exact pending File and its filename review while moving the chooser into the HUD');
 const reopenedHero=reopenFrame.contentDocument.querySelector('#hero');
 reopenedHero.click();
 const reopenedInspector=reopenDialog.querySelector('.theme-visual-editor-inspector');
 [...reopenedInspector.querySelectorAll('button')].find(button=>button.textContent==='Style element').click();
 const reopenedHeroSelector=reopenedInspector.querySelector('code')?.textContent||'';
 const resetField=[...reopenedInspector.querySelectorAll('fieldset')].find(field=>field.querySelector('legend')?.textContent==='background-color');
 resetField.querySelector('button').click();
 check(reopenFrame.contentWindow.getComputedStyle(reopenedHero,'::before').backgroundImage.includes('linear-gradient'),'reset after reopening removes only the persisted owned companion and restores the Theme gradient');
 const reopenedScope=Array.from(reopenedInspector.querySelectorAll('select'))
      .find(select=>select.getAttribute('aria-label')===root.dataset.visualEditorScopeLabel);
 reopenedScope.value='responsive:tablet';
 reopenedScope.dispatchEvent(new Event('change',{bubbles:true}));
 const responsiveColor=[...reopenedInspector.querySelectorAll('fieldset')].find(field=>field.querySelector('legend')?.textContent==='color');
 responsiveColor.querySelector('button').click();
 reopenDialog.querySelector('[data-visual-editor-apply-exit]').click();
 const resetManagedDraft=parseVisualCssDraft(text.value);
 const appliedManagedDraft=parseVisualCssDraft(appliedCss);
 const isHeroBackground=rule=>rule.scope==='site'&&rule.selector===reopenedHeroSelector&&rule.property==='background-color';
 const isResponsiveHeroColor=rule=>rule.scope==='responsive:tablet'&&rule.selector===reopenedHeroSelector&&rule.property==='color';
 const isHeroBackgroundCompanion=rule=>rule.companionOf?.scope==='site'
     &&rule.companionOf.selector===reopenedHeroSelector&&rule.companionOf.property==='background-color';
 const appliedHeroBackground=appliedManagedDraft.rules.some(isHeroBackground);
 const appliedResponsiveHeroColor=appliedManagedDraft.rules.some(isResponsiveHeroColor);
 const appliedHeroBackgroundCompanion=appliedManagedDraft.rules.some(isHeroBackgroundCompanion);
 const expectedRemainingRules=appliedManagedDraft.rules.filter(rule=>!isHeroBackground(rule)
     &&!isResponsiveHeroColor(rule)&&!isHeroBackgroundCompanion(rule));
 const resetManagedDiagnostics={parsed:resetManagedDraft.valid&&appliedManagedDraft.valid,
     manualBytes:resetManagedDraft.baseCss===originalDraft,
     targetsExisted:appliedHeroBackground&&appliedResponsiveHeroColor&&appliedHeroBackgroundCompanion,
     targetsRemoved:!resetManagedDraft.rules.some(isHeroBackground)&&!resetManagedDraft.rules.some(isResponsiveHeroColor)
         &&!resetManagedDraft.rules.some(isHeroBackgroundCompanion),
     unrelatedRulesPreserved:expectedRemainingRules.length>0
         &&JSON.stringify(resetManagedDraft.rules)===JSON.stringify(expectedRemainingRules)};
 check(Object.values(resetManagedDiagnostics).every(Boolean),
     'reopened property resets remove only their scoped declarations and preserve manual bytes and unrelated rules: '
         +JSON.stringify(resetManagedDiagnostics));

 const entryWidthRules=applyVisualCssDraftChanges(parseVisualCssDraft(originalDraft),[
  {scope:'site',selector:'body.public-page.public-page .site-header',property:'width',value:'min(1325px,calc(100% - 2rem))'},
  {scope:'site',selector:'body.public-page.public-page .site-main',property:'width',value:'min(1325px,calc(100% - 2rem))'},
  {scope:'site',selector:'body.public-page.public-page .site-footer',property:'width',value:'min(1325px,calc(100% - 2rem))'}
 ]);
 const widthEntryCss=serializeVisualCssDraft(entryWidthRules);
 text.value=widthEntryCss;text.dispatchEvent(new Event('input',{bubbles:true}));
 stage3Launch.click();
 const entryWidthDialog=document.querySelector('.theme-visual-editor-workspace');
 const entryWidthFrame=entryWidthDialog.querySelector('iframe');
 await waitForFrame(entryWidthFrame);
 entryWidthFrame.style.maxWidth='none';entryWidthFrame.style.width='1600px';
 await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
 const entryWidthMode=entryWidthDialog.querySelector('select[aria-label="Page width mode"]');
 const entryWidthNumber=entryWidthDialog.querySelector('[data-visual-editor-page-width-number]');
 check(entryWidthMode.value==='custom'&&entryWidthNumber.value==='1325'&&!entryWidthNumber.disabled
     &&Math.abs(entryWidthFrame.contentDocument.querySelector('.site-main').getBoundingClientRect().width-1325)<1,
     'managed entry CSS overrides the saved Wide/1600 metadata and initializes Custom from the actual 1325-pixel rules');
 entryWidthMode.value='full';entryWidthMode.dispatchEvent(new Event('change',{bubbles:true}));
 [...entryWidthDialog.querySelectorAll('button')].find(button=>button.textContent==='Undo').click();
 check(entryWidthMode.value==='custom'&&entryWidthNumber.value==='1325'
     &&Math.abs(entryWidthFrame.contentDocument.querySelector('.site-main').getBoundingClientRect().width-1325)<1,
     'Undo restores the exact inferred entry width mode and custom preference');
 entryWidthMode.value='wide';entryWidthMode.dispatchEvent(new Event('change',{bubbles:true}));
 entryWidthDialog.querySelector('[data-visual-editor-reset-session]').click();
 check(entryWidthMode.value==='custom'&&entryWidthNumber.value==='1325'&&text.value===widthEntryCss
     &&Math.abs(entryWidthFrame.contentDocument.querySelector('.site-main').getBoundingClientRect().width-1325)<1,
     'Reset session restores exact entry CSS bytes and normalized width state despite different saved Theme metadata');
 entryWidthDialog.querySelector('[data-visual-editor-cancel-exit]').click();
 check(!entryWidthDialog.isConnected&&text.value===widthEntryCss,
     'closing the unchanged entry snapshot preserves the exact managed CSS bytes');
 text.value=originalDraft;text.dispatchEvent(new Event('input',{bubbles:true}));

 const cancelEntry=text.value;
 stage3Launch.click();
 const cancelDialog=document.querySelector('.theme-visual-editor-workspace');
 const cancelFrame=cancelDialog.querySelector('iframe');
 await waitForFrame(cancelFrame);
 const cancelHero=cancelFrame.contentDocument.querySelector('#hero');
 cancelHero.click();
 const cancelInspector=cancelDialog.querySelector('.theme-visual-editor-inspector');
 [...cancelInspector.querySelectorAll('button')].find(button=>button.textContent==='Style element').click();
 const cancelBackground=[...cancelInspector.querySelectorAll('fieldset')].find(field=>field.querySelector('legend')?.textContent==='background-color');
 const cancelColor=cancelBackground.querySelector('input[type="color"]');
 cancelColor.value='#ff0000';
 cancelColor.dispatchEvent(new Event('change',{bubbles:true}));
 check(cancelFrame.contentWindow.getComputedStyle(cancelHero).backgroundColor==='rgb(255, 0, 0)','a pending edit is live in the preview before cancellation');
 const replacementImage=new File(['replacement image bytes'],'replacement.webp',{type:'image/webp'});
 const replacementTransfer=new DataTransfer();replacementTransfer.items.add(replacementImage);backgroundInput.files=replacementTransfer.files;
 backgroundInput.dispatchEvent(new Event('change',{bubbles:true}));
 check(cancelFrame.contentWindow.getComputedStyle(cancelFrame.contentDocument.querySelector('.theme-background-image')).backgroundImage.includes('blob:'),'replacing the pending image refreshes the isolated preview object URL');
 const replacedPreviewUrl=cancelFrame.contentDocument.querySelector('.theme-background-image').style.backgroundImage;
 [...cancelDialog.querySelectorAll('button')].find(button=>button.textContent==='Undo').click();
 const restoredPreviewUrl=cancelFrame.contentDocument.querySelector('.theme-background-image').style.backgroundImage;
 check(backgroundInput.files[0]?.name==='first.png'&&restoredPreviewUrl.includes('blob:')&&restoredPreviewUrl!==replacedPreviewUrl,'Undo restores the earlier File and recreates its matching object URL');
 [...cancelDialog.querySelectorAll('button')].find(button=>button.textContent==='Redo').click();
 const redonePreviewUrl=cancelFrame.contentDocument.querySelector('.theme-background-image').style.backgroundImage;
 check(backgroundInput.files[0]?.name==='replacement.webp'&&redonePreviewUrl.includes('blob:')&&redonePreviewUrl!==restoredPreviewUrl,'Redo restores the replacement File with a fresh matching object URL');
 [...cancelDialog.querySelectorAll('button')].find(button=>button.textContent==='Cancel & exit').click();
 check(!cancelDialog.querySelector('[data-visual-editor-exit-prompt]').hidden,'Cancel and exit asks before discarding CSS and File edits');
 cancelDialog.querySelector('[data-visual-editor-discard-session]').click();
 check(text.value===cancelEntry,'Cancel restores the exact textarea bytes captured when this visual session began');
 check(backgroundInput.files[0]?.name==='first.png','Cancel restores the exact pending File selection captured at visual-session entry');
 check(!backgroundInput.classList.contains('theme-visual-editor-file-input')&&backgroundInput.getClientRects().length>0
     &&backgroundStatus.textContent.includes('first.png'),'Cancel restores a visible native chooser and the matching pending filename review');

 const lifecycleEntry=text.value;
 stage3Launch.click();
 const lifecycleDialog=document.querySelector('.theme-visual-editor-workspace');
 const lifecycleFrame=lifecycleDialog.querySelector('iframe');
 await waitForFrame(lifecycleFrame);
 lifecycleFrame.contentDocument.querySelector('#hero').click();
 const lifecycleInspector=lifecycleDialog.querySelector('.theme-visual-editor-inspector');
 check(!lifecycleInspector.hidden,'the lifecycle fixture opens a real iframe selection before Escape');
 const exitReplacement=new File(['exit replacement'],'exit-replacement.png',{type:'image/png'});
 const exitTransfer=new DataTransfer();exitTransfer.items.add(exitReplacement);backgroundInput.files=exitTransfer.files;
 backgroundInput.dispatchEvent(new Event('change',{bubbles:true}));
 lifecycleFrame.contentDocument.body.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}));
 check(lifecycleInspector.hidden&&lifecycleDialog.isConnected,'Escape closes the local inspector before requesting workspace exit');
 lifecycleFrame.contentDocument.body.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}));
 const exitPrompt=lifecycleDialog.querySelector('[data-visual-editor-exit-prompt]');
 check(!exitPrompt.hidden&&text.value===lifecycleEntry,'Escape with no local popup opens the session choices without changing CSS bytes');
 lifecycleDialog.querySelector('[data-visual-editor-continue-editing]').click();
 check(exitPrompt.hidden&&lifecycleDialog.isConnected,'Continue editing closes only the exit prompt');
 lifecycleDialog.querySelector('[data-visual-editor-cancel-exit]').click();
 check(!exitPrompt.hidden,'the accessible Cancel and exit control opens the dirty-session choices');
 lifecycleDialog.querySelector('[data-visual-editor-discard-session]').click();
 check(!lifecycleDialog.isConnected&&text.value===lifecycleEntry&&backgroundInput.files[0]?.name==='first.png',
     'Discard session restores the exact entry CSS and File and closes without saving');

 stage3Launch.click();
 const resetDialog=document.querySelector('.theme-visual-editor-workspace');
 const resetFrame=resetDialog.querySelector('iframe');
 await waitForFrame(resetFrame);
 const resetViewportButton=resetDialog.querySelector('[data-visual-editor-viewport="mobile"]');
 resetViewportButton.click();
 const anonymousButton=resetDialog.querySelector('[data-visual-editor-audience="anonymous"]');
 const resetAudienceLoad=waitForFrameLoad(resetFrame,'switch to anonymous audience');
 anonymousButton.click();
 await resetAudienceLoad;
 await waitForFrame(resetFrame);
 const resetWidthMode=resetDialog.querySelector('select[aria-label="Page width mode"]');
 resetWidthMode.value='full';resetWidthMode.dispatchEvent(new Event('change',{bubbles:true}));
 const replacementForReset=new File(['reset image bytes'],'reset.png',{type:'image/png'});
 const resetTransfer=new DataTransfer();resetTransfer.items.add(replacementForReset);backgroundInput.files=resetTransfer.files;
 backgroundInput.dispatchEvent(new Event('change',{bubbles:true}));
 resetDialog.querySelector('[data-visual-editor-remove-background]').click();
 check(root.querySelector('[data-visual-editor-background-operation]').value==='remove'&&backgroundInput.files.length===0,
     'Reset session test stages an explicit removal after a replacement');
 resetDialog.querySelector('[data-visual-editor-reset-session]').click();
 check(backgroundInput.files[0]?.name==='first.png'&&root.querySelector('[data-visual-editor-background-operation]').value==='replace'
     &&text.value===lifecycleEntry,'Reset visual session restores its entry File and operation and leaves the authoritative textarea unchanged');
 check(Math.abs(resetFrame.getBoundingClientRect().width-390)<1&&anonymousButton.getAttribute('aria-pressed')==='true','Reset visual session preserves the current viewport and audience');
 resetWidthMode.value='wide';resetWidthMode.dispatchEvent(new Event('change',{bubbles:true}));
 resetDialog.querySelector('[data-visual-editor-cancel-exit]').click();
 check(!resetDialog.querySelector('[data-visual-editor-exit-prompt]').hidden,'pending Theme edits request an explicit exit choice');
 resetDialog.querySelector('[data-visual-editor-apply-session]').click();
 check(!resetDialog.isConnected&&text.value.includes('body.public-page.public-page .site-header'),'Apply from the exit choices hands the CSS draft back to the editor');
 check(backgroundInput.files[0]?.name==='first.png','Apply keeps the restored pending File for the separate explicit Save action');

 stage3Launch.click();
 const closeGalleryDialog=document.querySelector('.theme-visual-editor-workspace');
 const closeGalleryFrame=closeGalleryDialog.querySelector('iframe');
 await waitForFrame(closeGalleryFrame);
 closeGalleryFrame.contentDocument.querySelector('#gallery-link').click();
 const closeGalleryFollow=closeGalleryDialog.querySelector('[data-visual-editor-follow-link]');
 check(Boolean(closeGalleryFollow&&!closeGalleryFollow.hidden),'the selected gallery link offers explicit protected navigation before closing');
 const closeGalleryLoad=waitForFrameLoad(closeGalleryFrame,'Follow Link to the gallery');
 closeGalleryFollow.click();
 await closeGalleryLoad;
 check(closeGalleryFrame.contentDocument?.title==='Fixture gallery','explicit Follow commits the gallery document before reviewing the global target');
 check(root.querySelector('[data-visual-editor-background-status]').textContent.includes('open Home'),
     'a live gallery override makes global preview guidance visible while the workspace is open');
 closeGalleryDialog.querySelector('[data-visual-editor-apply-exit]').click();
 check(!closeGalleryDialog.isConnected&&root.querySelector('[data-visual-editor-background-operation]').value==='replace'
     &&backgroundInput.files[0]?.name==='first.png'
     &&root.querySelector('[data-visual-editor-background-source-review]').textContent==='Global Theme image'
     &&root.querySelector('[data-visual-editor-background-status]').textContent.includes('first.png')
     &&!root.querySelector('[data-visual-editor-background-status]').textContent.includes('open Home'),
     'Apply from a gallery returns to an honest Global Theme review and keeps the matching pending filename');

 const fitEntryCss=text.value;
 const fitEntryCssRevision=root.querySelector('[data-css-override-revision]').value;
 const fitEntryBackgroundRevision=root.querySelector('[data-visual-editor-background-revision]').value;
 stage3Launch.click();
 const fitDialog=document.querySelector('.theme-visual-editor-workspace');
 const fitFrame=fitDialog.querySelector('iframe');
 await waitForFrame(fitFrame);
 const fitLayer=fitFrame.contentDocument.querySelector('.theme-background-image');
 const fitSelect=fitDialog.querySelector('[data-visual-editor-background-fit]');
 const fitPositionX=fitDialog.querySelector('[data-visual-editor-background-position-x]');
 const fitPositionY=fitDialog.querySelector('[data-visual-editor-background-position-y]');
 const fitPositionXNumber=fitDialog.querySelector('[data-visual-editor-background-position-x-number]');
 const fitPositionYNumber=fitDialog.querySelector('[data-visual-editor-background-position-y-number]');
 const fitReset=fitDialog.querySelector('[data-visual-editor-background-reset-fit]');
 const positionReset=fitDialog.querySelector('[data-visual-editor-background-reset-position]');
 const fitUnsupported=fitDialog.querySelector('[data-visual-editor-background-value-unsupported]');
 const fitOverrideConflict=fitDialog.querySelector('[data-visual-editor-background-override-conflict]');
 check(fitLayer.dataset.themeBackgroundVisualEditorTarget==='theme'
     &&!fitSelect.closest('fieldset').hidden&&!fitPositionX.closest('fieldset').hidden
     &&fitSelect.value==='cover'&&fitPositionX.value==='50'&&fitPositionY.value==='50'
     &&fitPositionXNumber.value==='50'&&fitPositionYNumber.value==='50'
     &&fitPositionXNumber.type==='number'&&fitPositionYNumber.type==='number'
     &&fitPositionXNumber.min==='0'&&fitPositionXNumber.max==='100'&&fitPositionXNumber.step==='1'
     &&fitPositionYNumber.min==='0'&&fitPositionYNumber.max==='100'&&fitPositionYNumber.step==='1'
     &&fitPositionXNumber.getAttribute('aria-label').includes('(%)')
     &&fitPositionYNumber.getAttribute('aria-label').includes('(%)')
     &&fitPositionX.closest('label').textContent.includes('(%)')
     &&fitPositionY.closest('label').textContent.includes('(%)')
     &&fitFrame.contentWindow.getComputedStyle(fitLayer).backgroundSize==='cover'
     &&fitUnsupported.hidden&&fitOverrideConflict.hidden,
     'Home exposes the global Theme fit and percentage-position controls at their saved defaults');
 const emptyAxisUndo=[...fitDialog.querySelectorAll('button')].find(button=>button.textContent==='Undo');
 const emptyAxisUndoDisabled=emptyAxisUndo.disabled;
 fitPositionXNumber.value='';fitPositionXNumber.dispatchEvent(new Event('change',{bubbles:true}));
 check(fitFrame.contentWindow.getComputedStyle(fitLayer).backgroundPosition==='50% 50%'
     &&fitPositionXNumber.value==='50'&&fitPositionYNumber.value==='50'
     &&emptyAxisUndo.disabled===emptyAxisUndoDisabled&&text.value===fitEntryCss
     &&root.querySelector('[data-css-override-revision]').value===fitEntryCssRevision
     &&root.querySelector('[data-visual-editor-background-revision]').value===fitEntryBackgroundRevision,
     'an empty numeric axis change restores the effective value without changing CSS history or saved revisions');
 const unsupportedPositionStyle=fitFrame.contentDocument.createElement('style');
 unsupportedPositionStyle.textContent='.theme-background-image{background-position:calc(20% + 1px) 30% !important}';
 fitFrame.contentDocument.head.append(unsupportedPositionStyle);
 fitPositionXNumber.value='';fitPositionXNumber.dispatchEvent(new Event('change',{bubbles:true}));
 const unsupportedDraftStyle=fitFrame.contentDocument.querySelector('[data-theme-visual-editor-draft]');
 const unsupportedDraftBefore=unsupportedDraftStyle?.textContent||'';
 const unsupportedUndoDisabled=emptyAxisUndo.disabled;
 check(fitFrame.contentWindow.getComputedStyle(fitLayer).backgroundPosition.includes('calc(')
     &&fitUnsupported.textContent.includes('calc(')&&!fitUnsupported.hidden
     &&fitPositionXNumber.value===''&&fitPositionYNumber.value===''&&fitPositionX.disabled&&fitPositionY.disabled,
     'an unsupported computed position stays visible and requires explicit values for both axes');
 fitPositionXNumber.value='25';fitPositionXNumber.dispatchEvent(new Event('input',{bubbles:true}));
 fitPositionXNumber.dispatchEvent(new Event('change',{bubbles:true}));
 check(fitFrame.contentWindow.getComputedStyle(fitLayer).backgroundPosition.includes('calc(')
     &&fitPositionXNumber.value==='25'&&fitPositionYNumber.value===''
     &&(fitFrame.contentDocument.querySelector('[data-theme-visual-editor-draft]')?.textContent||'')===unsupportedDraftBefore
     &&emptyAxisUndo.disabled===unsupportedUndoDisabled,
     'entering only one axis leaves the unsupported computed position and undo history unchanged');
 fitPositionYNumber.value='75';fitPositionYNumber.dispatchEvent(new Event('input',{bubbles:true}));
 fitPositionYNumber.dispatchEvent(new Event('change',{bubbles:true}));
 const explicitPairDraftStyle=fitFrame.contentDocument.querySelector('[data-theme-visual-editor-draft]');
 check((explicitPairDraftStyle?.textContent||'').includes('background-position: 25% 75%;')
     &&!fitUnsupported.hidden&&!fitOverrideConflict.hidden
     &&!emptyAxisUndo.disabled,
     'the explicit second axis commits the complete requested pair without inventing a default axis');
 unsupportedPositionStyle.remove();
 fitPositionXNumber.dispatchEvent(new Event('change',{bubbles:true}));
 check(fitFrame.contentWindow.getComputedStyle(fitLayer).backgroundPosition==='25% 75%'
     &&fitPositionXNumber.value==='25'&&fitPositionYNumber.value==='75',
     'the completed numeric pair becomes the effective percentage position when the stronger rule is removed');
 fitSelect.value='contain';fitSelect.dispatchEvent(new Event('change',{bubbles:true}));
 fitPositionX.value='100';fitPositionX.dispatchEvent(new Event('input',{bubbles:true}));
 fitPositionX.dispatchEvent(new Event('change',{bubbles:true}));
 fitPositionYNumber.value='0';fitPositionYNumber.dispatchEvent(new Event('input',{bubbles:true}));
 fitPositionYNumber.dispatchEvent(new Event('change',{bubbles:true}));
 check(fitFrame.contentWindow.getComputedStyle(fitLayer).backgroundSize==='contain'
     &&fitFrame.contentWindow.getComputedStyle(fitLayer).backgroundPosition==='100% 0%'
     &&fitPositionXNumber.value==='100'&&fitPositionY.value==='0'
     &&fitDialog.querySelector('[data-visual-editor-background-position-x-effective]').textContent==='100%'
     &&fitDialog.querySelector('[data-visual-editor-background-position-y-effective]').textContent==='0%'
     &&fitUnsupported.hidden&&fitOverrideConflict.hidden&&text.value===fitEntryCss
     &&root.querySelector('[data-css-override-revision]').value===fitEntryCssRevision
     &&root.querySelector('[data-visual-editor-background-revision]').value===fitEntryBackgroundRevision,
     'fit and percentage axes change the computed Theme image presentation while remaining an unsaved local draft');
 const fitConflictStyle=fitFrame.contentDocument.createElement('style');
 fitConflictStyle.textContent='.theme-background-image{background-size:cover!important}';
 fitFrame.contentDocument.head.append(fitConflictStyle);
 fitSelect.value='contain';fitSelect.dispatchEvent(new Event('change',{bubbles:true}));
 check(fitFrame.contentWindow.getComputedStyle(fitLayer).backgroundSize==='cover'
     &&!fitOverrideConflict.hidden&&fitOverrideConflict.getAttribute('role')==='status',
     'a stronger page rule exposes a visible status when the managed fit differs from computed output');
 fitConflictStyle.remove();
 fitSelect.value='contain';fitSelect.dispatchEvent(new Event('change',{bubbles:true}));
 check(fitFrame.contentWindow.getComputedStyle(fitLayer).backgroundSize==='contain'
     &&fitOverrideConflict.hidden,
     'the fit conflict status clears when effective computed and managed values agree');
 fitReset.click();positionReset.click();
 check(fitFrame.contentWindow.getComputedStyle(fitLayer).backgroundSize==='cover'
     &&fitFrame.contentWindow.getComputedStyle(fitLayer).backgroundPosition==='50% 50%'
     &&fitReset.disabled&&positionReset.disabled&&fitSelect.value==='cover'
     &&fitPositionX.value==='50'&&fitPositionY.value==='50'
     &&fitPositionXNumber.value==='50'&&fitPositionYNumber.value==='50'
     &&fitDialog.querySelector('[data-visual-editor-background-position-x-effective]').textContent==='50%'
     &&fitDialog.querySelector('[data-visual-editor-background-position-y-effective]').textContent==='50%',
     'independent fit and position resets reveal the saved Theme image defaults');
 [...fitDialog.querySelectorAll('button')].find(button=>button.textContent==='Undo').click();
 [...fitDialog.querySelectorAll('button')].find(button=>button.textContent==='Undo').click();
 check(fitFrame.contentWindow.getComputedStyle(fitLayer).backgroundSize==='contain'
     &&fitFrame.contentWindow.getComputedStyle(fitLayer).backgroundPosition==='100% 0%'
     &&fitPositionXNumber.value==='100'&&fitPositionYNumber.value==='0',
     'Undo restores the paired custom fit and position after both reset actions');
 fitPositionXNumber.value='75';fitPositionXNumber.dispatchEvent(new Event('input',{bubbles:true}));
 fitPositionXNumber.dispatchEvent(new Event('change',{bubbles:true}));
 check(fitFrame.contentWindow.getComputedStyle(fitLayer).backgroundPosition==='75% 0%'
     &&fitPositionX.value==='75'&&fitPositionXNumber.value==='75'
     &&fitDialog.querySelector('[data-visual-editor-background-position-x-effective]').textContent==='75%'
     &&fitDialog.querySelector('[data-visual-editor-background-position-y-effective]').textContent==='0%',
     'the keyboard-editable horizontal percentage field synchronizes its slider and computed image position');
 const fitUndo=[...fitDialog.querySelectorAll('button')].find(button=>button.textContent==='Undo');
 const fitRedo=[...fitDialog.querySelectorAll('button')].find(button=>button.textContent==='Redo');
 fitUndo.click();
 check(fitFrame.contentWindow.getComputedStyle(fitLayer).backgroundPosition==='100% 0%'
     &&fitPositionXNumber.value==='100'
     &&fitDialog.querySelector('[data-visual-editor-background-position-x-effective]').textContent==='100%',
     'Undo restores the preceding numeric-axis draft');
 fitRedo.click();
 check(fitFrame.contentWindow.getComputedStyle(fitLayer).backgroundPosition==='75% 0%'
     &&fitPositionXNumber.value==='75'
     &&fitDialog.querySelector('[data-visual-editor-background-position-x-effective]').textContent==='75%',
     'Redo restores the numeric-axis draft and synchronized control value');
 fitDialog.querySelector('[data-visual-editor-apply-exit]').click();
 check(text.value.includes('background-size: contain;')&&text.value.includes('background-position: 75% 0%;')
     &&root.querySelector('[data-css-override-revision]').value===fitEntryCssRevision
     &&root.querySelector('[data-visual-editor-background-revision]').value===fitEntryBackgroundRevision,
     'Apply hands fit and position declarations to the CSS textarea without saving a revision');
 stage3Launch.click();
 const fitReentryDialog=document.querySelector('.theme-visual-editor-workspace');
 const fitReentryFrame=fitReentryDialog.querySelector('iframe');
 await waitForFrame(fitReentryFrame);
 const fitReentryLayer=fitReentryFrame.contentDocument.querySelector('.theme-background-image');
 check(fitReentryFrame.contentWindow.getComputedStyle(fitReentryLayer).backgroundSize==='contain'
     &&fitReentryFrame.contentWindow.getComputedStyle(fitReentryLayer).backgroundPosition==='75% 0%'
     &&fitReentryDialog.querySelector('[data-visual-editor-background-fit]').value==='contain'
     &&fitReentryDialog.querySelector('[data-visual-editor-background-position-x]').value==='75'
     &&fitReentryDialog.querySelector('[data-visual-editor-background-position-y]').value==='0'
     &&fitReentryDialog.querySelector('[data-visual-editor-background-position-x-number]').value==='75'
     &&fitReentryDialog.querySelector('[data-visual-editor-background-position-y-number]').value==='0'
     &&fitReentryDialog.querySelector('[data-visual-editor-background-position-x-effective]').textContent==='75%'
     &&fitReentryDialog.querySelector('[data-visual-editor-background-position-y-effective]').textContent==='0%'
     &&root.querySelector('[data-css-override-revision]').value===fitEntryCssRevision
     &&root.querySelector('[data-visual-editor-background-revision]').value===fitEntryBackgroundRevision,
     're-entry reconstructs the computed fit and position controls from the applied managed CSS block');
 [...fitReentryDialog.querySelectorAll('button')].find(button=>button.textContent==='Cancel & exit').click();
 check(!fitReentryDialog.isConnected,'an unchanged fit/position re-entry closes without a new draft action');
 root.querySelector('[data-css-override-restore-saved]').click();
 check(text.value===originalDraft&&root.querySelector('[data-css-override-revision]').value==='saved-revision'
     &&backgroundInput.files.length===0&&root.querySelector('[data-visual-editor-background-operation]').value==='keep',
     'Restore saved CSS clears the isolated fit/position probe and pending file without a request');

 text.value='/* PHP Gallery managed visual CSS BEGIN damaged';
 const malformedEntry=text.value;
 stage3Launch.click();
 const malformedDialog=document.querySelector('.theme-visual-editor-workspace');
 const malformedFrame=malformedDialog.querySelector('iframe');
 await waitForFrame(malformedFrame);
 check(malformedDialog.querySelector('[data-visual-editor-apply-exit]').disabled,'malformed managed CSS disables Apply');
 const malformedHero=malformedFrame.contentDocument.querySelector('#hero');
 malformedHero.click();
 const malformedInspector=malformedDialog.querySelector('.theme-visual-editor-inspector');
 check([...malformedDialog.querySelectorAll('[role="alert"]')].some(node=>node.textContent.includes('malformed')),'malformed managed CSS displays a repair warning');
 check([...malformedInspector.querySelectorAll('button')].find(button=>button.textContent==='Style element').disabled,'malformed managed CSS disables property mutation');
 [...malformedDialog.querySelectorAll('button')].find(button=>button.textContent==='Cancel & exit').click();
 check(text.value===malformedEntry,'malformed source bytes remain exact after leaving the editor');

 document.getElementById('results').textContent='BROWSER PASS '+assertions+' visual editor assertions';
}catch(error){document.getElementById('results').textContent='BROWSER FAIL '+error.message+'\\n'+error.stack+(window.__visualEditorRuntimeError?'\\nCaptured runtime error: '+window.__visualEditorRuntimeError:'');}
</script></body></html>`);
}

const browserExecutable = process.argv[2];
if (!browserExecutable) {
    console.log('SKIP visual CSS editor browser: no Chromium executable supplied.');
    process.exit(0);
}

const mutationRequests = [];
// Type: number.
// Units: milliseconds.
// Scope: one native keyboard or resize interaction in this isolated browser fixture.
// Consumers: parent-driver polling for page-published input requests, transient resize preview, and initial fixture readiness.
// Rationale: polling synchronous DevTools evaluations keeps the protocol socket free while the page completes real user-input assertions.
const visualEditorInputRequestTimeoutMs = 20000;
// Type: number.
// Units: milliseconds.
// Scope: interval between native keyboard/resize request polls in this fixture.
// Consumers: parent-driver polling while an input request or its observable transient preview is pending.
// Rationale: short sleeps avoid busy-spinning while preserving responsive pointer and keyboard gestures.
const visualEditorInputPollIntervalMs = 50;
const server = createServer((request, response) => {
    if (request.method !== 'GET') mutationRequests.push(request.method || 'unknown');
    serveFixture(request, response).catch(() => response.writeHead(500).end('Fixture failed'));
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
try {
    const address = server.address();
    if (!address || typeof address === 'string') throw new Error('The owned loopback fixture did not receive a TCP port.');
const browserUrl = `http://127.0.0.1:${address.port}/`;
    const result = await runHeadlessBrowserFixture(browserExecutable, browserUrl, 'theme-visual-editor-', {
        interact: async driver => {
            /**
             * Read a page-side failure that occurred before the browser handshake completed.
             * @returns {Promise<string>} Bounded assertion or runtime failure text, or an empty string while the fixture is healthy.
             */
            async function readFixtureFailure() {
                return driver.evaluate('(()=>{const result=String(document.getElementById("results")?.textContent||"");if(result.startsWith("BROWSER FAIL"))return result.slice(0,3000);const error=String(window.__visualEditorRuntimeError||"");return error?"Browser runtime error: "+error.slice(0,3000):"";})()');
            }

            /**
             * Wait until the page module has installed the visual editor before dispatching native input.
             * @returns {Promise<void>} Resolves after the fixture's editor setup handshake or reports a bounded startup failure.
             */
            async function waitForFixtureReady() {
                const deadline = Date.now() + visualEditorInputRequestTimeoutMs;
                while (Date.now() < deadline) {
                    const failure = await readFixtureFailure();
                    if (failure) throw new Error('The visual editor fixture failed before setup completed: ' + failure);
                    if (await driver.evaluate('window.__visualEditorTestReady === true')) return;
                    await new Promise(resolve => setTimeout(resolve, visualEditorInputPollIntervalMs));
                }
                const failure = await readFixtureFailure();
                throw new Error(failure || 'The visual editor fixture did not finish installing its native input handlers.');
            }

            /**
             * Complete one real browser keypress requested by the served editor fixture.
             * @param {string} phase Safe action label used in bounded failure diagnostics.
             * @returns {Promise<void>} Sends keyDown/keyUp to the focused DOM control and releases the page-side wait.
             */
            async function performNativeKey(phase) {
                const deadline = Date.now() + visualEditorInputRequestTimeoutMs;
                let request = null;
                while (Date.now() < deadline) {
                    const failure = await readFixtureFailure();
                    if (failure) throw new Error('The visual editor fixture failed before ' + phase + ': ' + failure);
                    request = await driver.evaluate('window.__visualKeyboardPendingRequest || null');
                    if (request) break;
                    await new Promise(resolve => setTimeout(resolve, visualEditorInputPollIntervalMs));
                }
                if (!request) {
                    const failure = await readFixtureFailure();
                    throw new Error(failure || 'The page did not publish the requested keyboard action: ' + phase);
                }
                assert.equal(request.phase, phase, 'The browser requested the expected keyboard interaction.');
                assert.ok(request.key === 'Enter' || request.key === 'Space', 'The fixture requests a supported native activation key.');
                await driver.evaluate('window.__visualKeyboardPendingRequest = null');
                await driver.key('keyDown', request.key);
                await driver.key('keyUp', request.key);
                await driver.evaluate('window.__visualKeyboardCompletion()');
            }

            /**
             * Perform one owned native pointer gesture against the current parent-document handle.
             * @param {'commit'|'escape'} phase Commit normally or send Escape after preview movement.
             * @returns {Promise<void>} Completes one externally coordinated fixture resize request after checking temporary overlay state.
             */
            async function performResize(phase) {
                const deadline = Date.now() + visualEditorInputRequestTimeoutMs;
                let request = null;
                while (Date.now() < deadline) {
                    const failure = await readFixtureFailure();
                    if (failure) throw new Error('The visual editor fixture failed before publishing the ' + phase + ' resize gesture: ' + failure);
                    request = await driver.evaluate('window.__visualResizePendingRequest || null');
                    if (request) break;
                    await new Promise(resolve => setTimeout(resolve, visualEditorInputPollIntervalMs));
                }
                if (!request) {
                    const failure = await readFixtureFailure();
                    throw new Error(failure || 'The page did not publish the requested ' + phase + ' resize gesture.');
                }
                await driver.evaluate('window.__visualResizePendingRequest = null');
                await driver.evaluate('window.__visualResizePointerTrace = []');
                await driver.evaluate('window.__visualResizeBubbleTrace = []');
                await driver.evaluate('window.__visualResizeCaptureTrace = {installed:false,called:false,outcome:"not_called",error:"",pointerId:null,hasCaptureAfterReturn:false,handleConnectedAfterReturn:false,ownerDocumentMatchesAfterReturn:false}');
                assert.equal(request.phase, phase, 'The browser requested the expected resize interaction.');
                const overlayBefore = await driver.evaluate(
                    'window.__visualResizeOverlayState("before",' + request.x + ',' + request.y + ')',
                );
                assert.equal(overlayBefore.computedPointerEvents, 'none', 'The resize overlay starts with its normal hit-testing policy: ' + JSON.stringify(overlayBefore));
                const initialHit = await driver.evaluate(
                    '(()=>{const hit=document.elementFromPoint(' + request.x + ',' + request.y + ');const handle=hit instanceof Element?hit.closest(".theme-visual-css-resize-handle"):null;const rect=handle?.getBoundingClientRect();return {handle:Boolean(handle),edge:handle?.dataset.resizeEdge||"",visible:Boolean(rect&&rect.width>0&&rect.height>0&&getComputedStyle(handle).display!=="none"),hitWithinHandle:Boolean(rect&&' + request.x + '>=rect.left&&' + request.x + '<=rect.right&&' + request.y + '>=rect.top&&' + request.y + '<=rect.bottom)};})()',
                );
                assert.ok(
                    initialHit.handle && initialHit.edge === 'bottom' && initialHit.visible && initialHit.hitWithinHandle,
                    'The native pointer starts over the visible bottom resize handle: ' + JSON.stringify(initialHit),
                );
                const resizeEligibility = await driver.evaluate(
                    'window.__visualResizeEligibility(' + request.x + ',' + request.y + ',"bottom")',
                );
                const captureProbeInstalled = await driver.evaluate(
                    'window.__visualResizeInstallCaptureProbe(' + request.x + ',' + request.y + ')',
                );
                await driver.mouse('mouseMoved', request.x, request.y, 0);
                await driver.mouse('mousePressed', request.x, request.y, 1);
                const captureAfterPress = await driver.evaluate('window.__visualResizeReadCaptureState("after-press")');
                const overlayAfterPress = await driver.evaluate(
                    'window.__visualResizeOverlayState("after-press",' + request.x + ',' + request.y + ')',
                );
                assert.equal(overlayAfterPress.computedPointerEvents, 'auto', 'The overlay shield enables parent hit testing for the active pointer: ' + JSON.stringify(overlayAfterPress));
                assert.equal(overlayAfterPress.hitInsideOverlay, true, 'The active pointer position remains within the parent resize overlay: ' + JSON.stringify(overlayAfterPress));
                await driver.mouse('mouseMoved', request.x, request.y + request.delta, 1);
                const captureAfterMove = await driver.evaluate('window.__visualResizeReadCaptureState("after-move")');
                const overlayAfterMove = await driver.evaluate(
                    'window.__visualResizeOverlayState("after-move",' + request.x + ',' + (request.y + request.delta) + ')',
                );
                assert.equal(overlayAfterMove.computedPointerEvents, 'auto', 'Parent hit testing remains enabled while the pointer moves: ' + JSON.stringify(overlayAfterMove));
                assert.equal(overlayAfterMove.hitInsideOverlay, true, 'The moved pointer remains in the parent overlay hit-test region: ' + JSON.stringify(overlayAfterMove));
                const previewDeadline = Date.now() + visualEditorInputRequestTimeoutMs;
                let gestureState = {preview: false, trace: [], bubbleTrace: [], captureTrace: {called: false, outcome: 'not_called'}};
                while (Date.now() < previewDeadline) {
                    const failure = await readFixtureFailure();
                    if (failure) throw new Error('The visual editor fixture failed while awaiting transient resize preview: ' + failure);
                    gestureState = await driver.evaluate(
                        '(()=>{const frame=document.querySelector(".theme-visual-editor-workspace iframe");const preview=frame?.contentDocument?.querySelector("[data-visual-css-resize-preview]");return {preview:Boolean(preview),trace:Array.isArray(window.__visualResizePointerTrace)?window.__visualResizePointerTrace.slice(0,12):[],bubbleTrace:Array.isArray(window.__visualResizeBubbleTrace)?window.__visualResizeBubbleTrace.slice(0,4):[],captureTrace:window.__visualResizeCaptureTrace||{called:false,outcome:"missing"}};})()',
                    );
                    if (gestureState.preview) break;
                    await new Promise(resolve => setTimeout(resolve, visualEditorInputPollIntervalMs));
                }
                const previewed = gestureState.preview;
                if (!previewed) {
                    throw new Error('Native pointer movement did not render a transient resize stylesheet before release: '
                        + JSON.stringify({initialHit,resizeEligibility,captureProbeInstalled,
                            trace:gestureState.trace,bubbleTrace:gestureState.bubbleTrace,captureTrace:gestureState.captureTrace,
                            captureAfterPress,captureAfterMove}));
                }
                const activePointerMoves = gestureState.trace.filter(event => event.type === 'pointermove'
                    && event.buttons > 0 && event.pointerId === captureAfterMove.pointerId
                    && event.trusted === true && (event.target === 'handle' || event.overlayTarget));
                assert.ok(
                    activePointerMoves.length > 0,
                    'A trusted held pointer move for the active gesture reaches the shared resize listener: '
                        + JSON.stringify({trace:gestureState.trace,captureAfterMove}),
                );
                if (captureAfterMove.hasPointerCapture) {
                    assert.ok(
                        activePointerMoves.some(event => event.target === 'handle' && event.captured),
                        'When native capture remains active, the held move is routed through the captured handle: '
                            + JSON.stringify({activePointerMoves,captureAfterMove}),
                    );
                } else {
                    assert.ok(
                        activePointerMoves.some(event => event.overlayContainsTarget && !event.captured),
                        'When native capture is lost, the held move is routed through the active overlay shield: '
                            + JSON.stringify({activePointerMoves,captureAfterMove}),
                    );
                }
                let cancelled = false;
                let repositioned = false;
                let overlayAfterEscape = null;
                if (phase === 'escape') {
                    await driver.key('keyDown', 'Escape');
                    await driver.key('keyUp', 'Escape');
                    overlayAfterEscape = await driver.evaluate(
                        'window.__visualResizeOverlayState("after-escape",' + request.x + ',' + (request.y + request.delta) + ')',
                    );
                    cancelled = !(await driver.evaluate(
                        'Boolean(document.querySelector(".theme-visual-editor-workspace iframe")?.contentDocument?.querySelector("[data-visual-css-resize-preview]"))',
                    ));
                    repositioned = await driver.evaluate(
                        '(()=>{const f=document.querySelector(".theme-visual-editor-workspace iframe");const t=f?.contentDocument?.querySelector("#hero");const h=document.querySelector(".theme-visual-css-resize-handle[data-resize-edge=bottom]");if(!f||!t||!h)return false;const fr=f.getBoundingClientRect();const tr=t.getBoundingClientRect();const hr=h.getBoundingClientRect();return Math.abs(hr.top+hr.height/2-(fr.top+f.clientTop+tr.bottom))<1;})()',
                    );
                } else {
                    await driver.mouse('mouseReleased', request.x, request.y + request.delta, 0);
                }
                if (phase === 'escape') await driver.mouse('mouseReleased', request.x, request.y + request.delta, 0);
                const overlayAfterFinish = await driver.evaluate(
                    'window.__visualResizeOverlayState("after-finish",' + request.x + ',' + (request.y + request.delta) + ')',
                );
                assert.equal(overlayAfterFinish.inlinePointerEvents, overlayBefore.inlinePointerEvents, 'Finishing the gesture restores the overlay inline hit-testing value.');
                assert.equal(overlayAfterFinish.computedPointerEvents, overlayBefore.computedPointerEvents, 'Finishing the gesture restores the overlay computed hit-testing policy.');
                if (overlayAfterEscape) {
                    assert.equal(overlayAfterEscape.inlinePointerEvents, overlayBefore.inlinePointerEvents, 'Escape restores the overlay before native pointer release.');
                    assert.equal(overlayAfterEscape.computedPointerEvents, overlayBefore.computedPointerEvents, 'Escape restores computed overlay hit testing before release.');
                }
                const resultExpression = 'window.__visualResizeCompletion(' + JSON.stringify({previewed, cancelled, repositioned}) + ')';
                await driver.evaluate(resultExpression);
            }

            await waitForFixtureReady();
            await performNativeKey('select linked preview anchor with Enter');
            await performNativeKey('select gallery-card ancestor with Space');
            await performNativeKey('reselect gallery anchor with Space');
            await performNativeKey('open Style element with Enter');
            await performNativeKey('follow gallery link with Space');
            await performResize('commit');
            await performResize('escape');
        },
    });
    assert.equal(result.exitCode, 0, 'Owned Chromium exits successfully.');
    assert.match(result.result, /^BROWSER PASS/, result.result || 'The visual editor fixture did not report completion.');
    assert.deepEqual(mutationRequests, [], 'Preview controls never submit a persistent request.');
    console.log(result.result);
    for (const scenario of ['workspace', 'draft', 'resize', 'import', 'initialization']) {
        const availabilityUrl = new URL('/availability/?case=' + scenario, browserUrl).href;
        const availability = await runHeadlessBrowserFixture(browserExecutable, availabilityUrl, 'theme-visual-availability-');
        assert.equal(availability.exitCode, 0, 'Availability fixture browser exits successfully.');
        assert.match(availability.result, /^BROWSER PASS/, availability.result || 'Availability fixture did not finish.');
        console.log(availability.result);
    }
    assert.deepEqual(mutationRequests, [], 'Failure fallbacks and Manual CSS draft controls never submit a persistent request.');
} finally {
    await new Promise(resolve => server.close(resolve));
}
