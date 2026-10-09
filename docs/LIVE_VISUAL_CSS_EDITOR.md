# Live Visual CSS Editor

This document records the integration boundaries, security contract, and stage
exit evidence for issue [#127](https://github.com/klusik/PHP_gallery/issues/127).
The editor is a draft-only workspace over the existing Theme CSS override editor.
It does not own another stylesheet, Theme setting, upload endpoint, or publication
path.

## Existing owners and boundaries

Issue #127 builds on the Theme CSS editor already present on
`feature/css_visual_editor`. Its integration points are:

- `app/views/admin_theme.php` renders the existing CSS textarea, saved revision,
  explicit Save/Reload/Clear actions, dirty state, and isolated sample preview.
- `public/assets/gallery-modules/theme-customization.js` owns the existing
  editor's client-side dirty tracking, navigation guard, and save transport.
  `theme-form.js` imports this module with an asset revision that must change
  when its behavior changes.
- `app/controllers/admin_theme_custom_css.php` prepares the editor view model;
  `app/controllers/admin_theme_actions.php` verifies CSRF and owns the explicit
  CSS override form transport.
- `app/services/custom_css.php` owns CSS validation, the 256 KiB UTF-8 limit,
  SHA-256 revision checks, serialized writes, atomic replacement, and the final
  `public/assets/custom-overrides.css` layer. Preset/upload CSS remains separate.
- `app/controllers/shared_layout.php` prepares ordered public stylesheet URLs
  from the current route and visual-preview audience. Its controller-owned model
  marks app-owned stylesheets and installed CSS layers with the same-origin
  preview context and prepares the background source context from the current
  gallery and effective public asset resolution; `app/views/layout.php` only
  renders those prepared URLs and typed background attributes into the public
  document shell. Public pages use `.site-header`, `.site-main`, `.site-footer`,
  and `.theme-background-shell`. Preview stylesheet paths start with the
  canonical `Gallery\Core\asset_url()` result before cache and preview markers
  are added, preserving the same asset path for repository-root and `public/`
  document-root deployments.
- `app/services/gallery_backgrounds.php` owns the distinction between the
  gallery's optional `background_source`, the Theme's gallery fallback mode,
  and the global uploaded Theme image. A resolved `upload` mode uses a gallery
  cover asset; `existing` uses a gallery cover photo; `collage` uses a
  gallery-derived collage. `theme_background_source` is the gallery fallback
  mode, not the global Theme image's upload identity. The scriptless preview
  reads only controller-prepared `data-theme-background-visual-editor-target`,
  `data-theme-background-visible-owner`, `data-theme-background-visible-mode`,
  and `data-theme-background-visible-url` values (`theme|none`,
  `theme_image|theme_gallery_fallback|gallery_override|none`, and
  `theme_image|upload|existing|collage|none`). The URL is the canonical
  same-origin asset chosen by the existing public resolver and is empty when no
  visible asset exists; it contains no filesystem path or private storage
  identity. A canonical public route may contain its gallery ID. The resolver
  classifies only the actual authorized/rendered URL, so anonymous simulation
  does not expose a hidden gallery asset's source.
- `app/bootstrap/request.php` may inherit context for an otherwise unmarked
  dependent-resource GET/HEAD only when its `Referer` has the exact request
  origin and remains inside the current application mount.
  `app/services/public_visual_preview.php` validates the context and
  `app/bootstrap/dispatch.php` still authenticates and allowlists each resolved
  route. The iframe uses `referrerpolicy="same-origin"`, so the full preview URL
  is not sent to cross-origin resources.
- `app/services/custom_css.php` owns installed-stylesheet inspection. It refuses
  a top-level `@import` before the browser can fetch its target and fails closed
  when a stylesheet cannot be inspected safely or the installed
  `public/assets/custom.css` exceeds the preview-only 8 MiB bound. Dispatch
  renders the typed refusal marker after preview authorization; the parent maps
  `import` and `inspection` to localized messages without exposing filesystem
  paths or errors. This is separate from the saved override editor's 256 KiB
  UTF-8 input limit.
- `app/controllers/theme_assets.php` emits the effective page-width, header,
  hero, and background CSS after built-in and uploaded custom styles. Width
  settings are normalized by `theme_page_width_mode()` and
  `theme_page_width_custom_value()` in `app/services/theme.php`.
- `app/helpers_request.php` owns `admin_anonymous_preview_active()` and
  `anonymous_preview_url()`. The existing anonymous mode is the `view_as=anonymous`
  query on an authenticated administrator's public request. Public controllers
  use this policy for visibility and to suppress administrator-only controls.
- `app/bootstrap/dispatch.php` is the canonical route and preflight boundary.
  Any new preview route or request mode must be authenticated, read-only, and
  included in the authored route dependency plan in
  `scripts/runtime_module_roots.php` (and any required reviewed dynamic targets
  in `scripts/runtime_dynamic_dependencies.php`); generated
  `app/runtime/modules.php` is not edited by hand.

For an authorized Home or Gallery preview, dispatch loads the single
`visual-preview-document` root, which contains both the CSS inspection and
background-context declarations. It inspects the installed stylesheet and
saved override first; a refusal ends the request before the shared layout calls
the background-context resolver. On success, the route prepares its shared
header and resolves the rendered background context. The deferred shared-layout
edge keeps gallery-background resolver dependencies out of ordinary media,
thumbnail, and Admin telemetry routes that reuse the layout.

Denied preview requests still render the standard not-found page through the
shared layout. That controller must use the same canonical preview decision as
dispatch before preparing editor metadata or calling the deferred background
resolver; the presence of a raw `preview` query value is not authorization.
The shared layout prepares background-editor metadata only for active, allowed
Home and Gallery documents. An allowed media or thumbnail route that later renders
an authorization 404 retains the normal private no-store error layout without
resolving the document-only background service.
The denial remains HTTP 404 with private no-store headers and does not disclose
the visual editor's background ownership attributes. The real HTTP fixture
asserts this boundary for a fresh visitor.

The preview must use real public controller output and the same stylesheet
cascade as the site. The Admin HUD belongs to the parent Admin document, outside
the user-styled public document. Keep the preview isolated from Admin styles and
state. A script-disabled same-origin iframe may be inspected by the parent; do
not combine `allow-scripts` and `allow-same-origin` in its sandbox. The frame must
not submit forms, activate links, open popups, navigate the top-level page, or
run public scripts. The parent performs navigation by loading a separately
validated same-origin public GET destination.

The preview document and its dependent private responses must use
`Cache-Control: private, no-store`. Anonymous simulation keeps the administrator
session for authorization of the workspace while applying the existing public
anonymous visibility rules. Check visibility and media authorization on every
request; do not implement anonymity by hiding already-rendered private data with
CSS or JavaScript. A private or gated gallery remains unavailable unless the
existing visitor grant permits it.

When an authenticated visual-preview request selects the Anonymous audience and
the resolved gallery is not permitted by the ordinary visitor policy, the
gallery controller redirects only that preview to the nearest structural
ancestor accepted by the same visitor policy, or to Home when none is
accessible. The redirect retains the visual-preview and anonymous-audience
markers and carries only the fixed `visual_notice=anonymous_fallback` token. The
Home canonical query guard preserves that token only when both markers match.
The HUD replaces it with a localized generic notice and removes the token from
subsequent navigation. The denied gallery document is not rendered. An otherwise
listed password-gated child may remain in its accessible parent's normal listing
as a locked card without cover imagery; private children are omitted by the
existing listing policy. A cyclic or incomplete parent chain fails closed to
Home. Ordinary visitor requests keep their existing password gate or not-found
behavior.

The agreed document entry contract is a controller-prepared, subdirectory-safe
`preview_url` generated with the application's URL helper. It targets the real
public home route and carries `preview=visual`. Public home/gallery navigation
within the frame retains that marker; signed-in simulation omits
`view_as=anonymous`, while anonymous simulation adds the existing
`view_as=anonymous` marker. Early SEO canonicalization keeps the preview and
audience markers and emits private no-store headers before any redirect. It
preserves the fixed fallback notice only when those exact markers accompany it; the
dispatcher still enforces the allowlisted route, Admin identity, and GET/HEAD
method. It permits only Admin-authenticated
GET/HEAD requests for the preview document and the audited public-render asset
routes. The route set must cover the actual emitted `theme_css`, public Theme
background/branding/favicon, gallery cover/branding, thumbnail, and media URLs.
Denied preview requests use the ordinary public not-found response after the
dispatcher loads its not-found module; an untrusted visitor receives no preview
document or editor metadata.
Reject the administrator-only original Theme background variant. Parent-side
navigation accepts only same-origin public destinations represented by
server-rendered anchors, and validates the route again after any redirect. It
never follows form actions, external links, file downloads, login routes, Admin
routes, or mutation endpoints. User text is never treated as a destination URL.

The workspace iframe uses `referrerpolicy="same-origin"`: a full preview
referrer is available only to same-origin dependent resource requests, while
cross-origin requests do not receive the preview URL as a referrer. Bootstrap
may inherit `preview=visual` and the anonymous audience marker from that
referrer only for GET/HEAD requests with exactly one valid marker, the exact
request origin and application mount, and no credentials or fragment. It rejects
cross-origin, out-of-mount, malformed, duplicated, or state-changing contexts.
This narrowly carries preview policy to dependent stylesheet/resource requests
that do not have the marker in their controller-prepared URL; it does not
authorize a new route, which is checked again by dispatch. The shared-layout
controller builds these resource URLs; the layout view does not infer preview
context or append security parameters.

Visual preview refuses stylesheets containing a top-level `@import` before the
browser can load the imported resource. The client checks the current CSS draft
before opening the workspace, and the server independently checks the installed
stylesheet and saved override before rendering a marked home/gallery document.
Its bounded inspection fails closed for unsafe links, non-regular/unreadable
files, and an installed `public/assets/custom.css` larger than 8 MiB. The
server returns HTTP 409 with the generic `import` or `inspection` marker and a
localized explanation; it does not expose filesystem paths or errors. The 8 MiB
bound applies only to preview inspection. It is separate from the saved CSS
override's 256 KiB UTF-8 input limit; Manual CSS remains available.

GET is not automatically read-only in this application. Public thumbnail
requests can repair/generate files and metadata, served media can record
telemetry, and benchmark query parameters can update benchmark logs. Visual
preview requests must not inherit benchmark query parameters or add Admin
preview traffic to visitor analytics. Before the preview route is accepted as
read-only, audit its source and resource requests and suppress persistent repair
or logging effects while retaining normal visibility checks. The anonymous
render must also suppress a separate viewer-account principal's personalized
favorites/collection data if it would not be available to a fresh anonymous
visitor. If a required page asset cannot be served without an unapproved write,
fail that asset safely or design an explicitly bounded transient rendering path;
do not silently classify a persistent repair as a read.

## Session model and editing contract

Keep navigation history separate from edit history. The workspace has the
following UI states: `idle`, `selecting`, `action-tooltip`, `styling`, `dragging`,
`navigating`, `applying`, and `exiting`. State belongs to the current workspace
instance and is never saved to the server. Its structured model contains the
entry textarea bytes and pending attachment state, current public URL, audience,
viewport preset/width, selected element and ancestor choices, edited declarations,
managed selector/property keys, resize transaction, and undo/redo entries.

The textarea value and existing editor submission remain the only CSS draft and
save path. Opening the workspace snapshots the exact textarea draft, including
already-unsaved manual edits. Inspection reads computed values for display but
does not serialize them. Only deliberate user changes become managed CSS.

During a resize drag, the parent overlay temporarily accepts pointer hit tests
for the full gesture. This keeps hit testing in the parent document while the
pointer crosses the scriptless preview iframe. The same overlay owns one
move/up/cancel listener set for the selection, so a trusted move reaches the
existing pointerId-guarded transaction whether the browser retargets it to the
captured handle or to the active shield. Commit, Escape, pointer cancellation,
frame teardown, and workspace cleanup restore the overlay's previous
hit-testing state.

Generated visual CSS occupies one deterministic managed block with a versioned
BEGIN marker, a Base64 declaration record, and an END marker. The model is
`public/assets/gallery-modules/theme-visual-css-draft.js`; it parses one owned
block, validates its record against the rendered declarations, and preserves
every source byte outside the block, including CRLF line endings. Repeated Apply
operations replace or remove only that block. Malformed, repeated, or user-edited
markers fail closed with the original CSS and a repair warning. Empty and no-op
sessions leave the textarea bytes unchanged.

The immutable model exports `parseVisualCssDraft`,
`updateVisualCssDraftProperty`, `removeVisualCssDraftProperty`,
`applyVisualCssDraftChanges`, `undoVisualCssDraft`, `redoVisualCssDraft`, and
`serializeVisualCssDraft`. A declaration is scoped to `site`,
`responsive:tablet` (max-width 768 px), or `responsive:mobile` (max-width 480
px). The scope is an explicit editing choice; changing the preview viewport does
not add or change media rules. The managed property allowlist is color,
background-color, font-size, font-weight, line-height, text-align,
text-decoration, border-radius, border-width, border-style, border-color,
box-shadow, gap, min-height, width, height, object-fit, object-position,
padding, and margin. Context profiles also expose the separately owned standard
and WebKit-prefixed `backdrop-filter` declarations for the public header and
hero, each with its own reset. Their values are limited to `none` or one bounded
blur plus one bounded saturation function; they do not admit URLs, variables,
arbitrary functions, pseudo-state selectors, or generic filter expressions.
The Links and buttons profile exposes foreground/background color with alpha,
typography, border, radius, padding, and shadow controls. Heading and paragraph
profiles also expose text decoration.
The global Theme image layer also supports the closed `background-size`
choices `cover` and `contain`, plus `background-position` as two integer
percentage axes from 0 to 100. Those properties are site-scope and restricted
to the permanent `body.public-page .theme-background-image` target.
The selector validator also permits a terminal `::before` or `::after`
companion to a valid stable selector. The additional `background-image`
property accepts only the literal `none`, allowing a hero background-color edit
to remove the real pseudo-element overlay in the same undoable action without
admitting an image URL or gradient. Its managed declaration stores typed
`companionOf` provenance linking it to the primary selector/scope/property, so
the link survives reload and reset. The editor creates an owned companion only
when no independent pseudo-element override already exists; reset removes only
the primary declaration and its matching owned companion, preserving an
independent managed rule and all hand-authored CSS. Invalid or dangling
provenance fails closed. Reset reveals the existing Theme gradient again.
Values use bounded literal CSS tokens and lengths; resource-loading functions,
variables, strings, escapes, comments, control characters, braces, and
statement separators are rejected. The inspector uses verified stable public
selectors and displays their match count; it does not serialize computed style
or depend on visible text or transient sibling position.

All visual changes, navigation, audience and viewport changes, width previews,
and image selection remain drafts. **Apply to CSS editor & exit** merges only the
managed CSS into the current textarea and carries a pending image for review; it
does not submit a request. **Cancel & exit** restores the exact entry draft and
attachment state. The only CSS publication boundary remains the existing
explicit Save action and its revision check. Existing destructive Clear remains
distinct from the new in-memory Clear draft action.

**Restore saved CSS** uses the last confirmed saved text and CSS revision already
rendered on the page or returned by the last successful Save. It replaces the
textarea and revision field, clears the pending File, and sends no fetch or save
request. It does not fetch or reconcile a save made by another tab or
administrator after that snapshot; a later explicit Save continues to use the
revision now present in the form and may be refused as stale.

The visual session must not persist `theme_page_width` or
`theme_page_width_custom`. HUD width uses the existing normalized Default, Wide,
Custom (1024–2048 px), and Full modes to preview header, main, and footer
together, then represents a deliberate override in the managed CSS block. Custom
width synchronizes a bounded slider and editable numeric pixel field. A separate
readout reports the effective rendered `.site-main` width from the active iframe,
so preset values and viewport clamping are not mistaken for the actual content
width. Reset width removes only that managed declaration and reveals the saved
Theme setting.

Background image editing reuses the existing Theme background validation,
opacity, optimization, and fallback owner. A newly selected file remains private
and pending until the administrator reviews and explicitly saves it through a
safe staged workflow. Selection and Apply do not upload or persist the file.
The persistent review area below the CSS textarea labels the current global
Theme image or empty state, its visible source/mode, and any pending operation.
The HUD offers Choose/Replace, Keep current, and Remove. It targets only the
global Theme image. When a gallery-specific asset or Theme gallery fallback is
currently rendered, the target is `none`: Replace/Remove are disabled with
guidance to open Home, and no gallery source or cover is changed. Keep remains
available to cancel a pending global operation. A pending global Theme
operation survives navigation but is not applied over that independent
gallery-derived layer.

The fit and position controls are available only when the real preview reports
the global Theme image layer as its target. They initialize from that layer's
computed `background-size` and `background-position`; the existing Theme CSS
baseline is `cover` and `center center`. Position keywords exposed by computed
style are mapped only when their percentage equivalent is unambiguous. Each
position axis shows the effective percentage and offers a slider plus a
keyboard-editable integer field from 0 through 100; the controls stay
synchronized and never convert pixel offsets. When the computed position cannot
be represented as percentage axes, both numeric fields must be entered before
the editor previews or records a replacement; it never fills the unknown axis
from a slider default. A value outside the closed fit/position controls is shown
as unsupported instead of being coerced; it can be changed in Manual CSS or
replaced with a supported control value. The
preview compares computed output with requested and managed values, and shows a
localized conflict when stronger handwritten CSS such as `!important` prevents
the override from taking effect. Each Reset removes only its corresponding
managed declaration and reveals the Theme baseline. Fit and position edits enter
the same managed CSS block and are saved only through the existing explicit CSS
Save action. They add no Theme setting or background mutation endpoint. These
declarations style the permanent
global Theme image layer; per-gallery background sources are rendered on the
gallery hero separately and are not changed by these controls. With no saved
global image, the controls may still prepare a draft for a pending image, but
the editor does not claim a visible image change until an image is available.

The existing protected `css_override_action=save` multipart request accepts
`theme_background_target=theme`,
`theme_background_operation=keep|replace|remove`, and the opaque
`theme_background_revision`; `theme_background_file` is present only for
Replace. Keep is the default and preserves CSS-only callers. Replace and Remove
remain in-memory draft operations until explicit Save. Remove clears only the
global Theme image's original/optimized files and image-path settings; it
preserves `theme_background_source` (the gallery fallback mode), every gallery
`background_source`, and gallery cover assets. Applying or choosing an image
does not select a different fallback mode. The controller returns the existing
CSS `state` plus a `background` snapshot with operation, target, availability,
revision, source and same-origin URL. A stale CSS or background revision returns
409 with null state snapshots so the browser keeps its draft and selected File.
The File never enters persisted CSS or a server-side draft file.

Background readiness is independent of CSS readiness. If the shared background
writer lock or a configured global image cannot be read for its revision, the
controller returns an unavailable background snapshot with no URL or revision.
The existing localized “Background image editing is unavailable” message is
shown and image Replace/Remove controls are disabled; Manual CSS and an explicit
CSS-only Keep Save remain available. A ready snapshot with no saved global image
is distinct and permits the first upload. If the controller cannot prepare a
safe visual-preview URL, launch remains hidden and fail-closed. The background
review/draft controls still initialize, but no actual preview frame is claimed
or shown.

The save service takes the CSS lock before the shared Theme background writer
lock. The background service validates the uploaded raster using the existing
Theme MIME policy, stages the original and optional WebP derivative under
unique names, and supplies only semantic settings to the application-settings
model. The model owns the SQL transaction and invokes reversible file activation
before commit. The service retains a verified copy of the prior CSS and keeps
the old background assets until the settings commit succeeds. Any staged-file,
CSS activation, settings, or commit failure makes the model attempt to roll back
settings while the service restores prior CSS bytes and permissions and removes
newly activated assets when rollback is confirmed. The request settings cache
changes only after a confirmed commit. A failed database rollback is reported as
an incomplete transaction; the service restores prior CSS but retains both old
and newly activated immutable background files because the database may still
reference the new one. The bounded response asks the administrator to review
Theme settings before retrying.
If restoring prior CSS bytes itself fails, the bounded controller response
reports whether a recovery copy remains and asks the administrator to check
server permissions. Existing direct POST/redirect and CSS-only saves remain
supported.

## Stage gates and required evidence

Stages are implemented as separate reviewable batches on the authorized feature
branch. A later edit invalidates earlier CI qualification. Do not close issue
#127 until every stage and final acceptance item is complete.

| Stage | Scope | Exit evidence |
| --- | --- | --- |
| 0. Architecture and contracts | Confirm #125 baseline, public routes and selectors, CSS save/dirty behavior, Theme width/background owners, state model, route isolation, and test ownership. | This document reflects the actual branch contracts; unresolved audience, attachment, read-only asset, and failure semantics are settled before dependent implementation. |
| 1. Real preview and HUD | Launch from the existing editor; render the genuine homepage and current unsaved CSS; add parent-owned Home/Back, title, audience, viewport, exit, and draft controls. | Authenticated signed-in and anonymous views render the actual site; anonymous access and private media remain gated; when an anonymous audience cannot access the current gallery, preview redirects to the nearest accessible ancestor or Home with a generic notice while preserving the draft; ordinary visitor gates remain unchanged; HUD stays available while scrolling and resizing; no mutation occurs. |
| 2. Inspection and navigation | Hover/click selection, small anchored Style/Follow tooltip, ancestor selection, stable selector/match count, background hit testing, and public route history. | A linked gallery card can be deliberately followed inside the frame; unlinked items have no Follow action; Back/Home preserve draft state; accidental actions and mutations are blocked. |
| 3. Styling and CSS round trip | Context profiles, effective property controls, CSS conflict reporting, per-property reset, the immutable managed-block model, Apply/Cancel, and initial Undo/Redo. | Hero alpha/radius and standard/WebKit backdrop filters, card presentation, links/buttons, and heading/paragraph text decoration produce stable reusable CSS; model regression covers exact bytes, safe values, round trips, scopes, and history; Apply never saves; Cancel restores exact pre-entry bytes. The real Admin/PHP browser journey then applies the reviewed CSS, saves through the existing form, reloads an unmarked public homepage at the same viewport, and compares computed main width, image opacity, manual CSS, and the normally delivered Theme image while requiring no editor-only draft style. |
| 4. Resize handles | Profile-approved pointer handles, layout constraints, bounded values, drag cancel, and one history operation per completed drag. | Hero supports vertical resize only; inline text and constrained axes have no misleading handles; eligible cards/media resize without breaking their layout. |
| 5. Width and backgrounds | HUD width modes and full-page reflow; server-reported background owner/mode; explicit global Theme Keep/Replace/Remove draft operations; disabled gallery-specific mutation; safe save/rollback integration. | Width mode and the last custom pixel value are visual-history state while the workspace is open; preset changes retain that custom value, Undo/Redo restore both, Reset visual session restores the entry CSS and controls, and Reset page width reveals the underlying Theme/CSS draft value. An unsaved managed width takes precedence over saved Theme Appearance width in the preview. Explicitly saving the CSS publishes that rule to public pages too; neither the draft nor saved CSS changes the Theme setting. Width edits serialize to CSS on Apply. Background operations remain pending until explicit Save; Remove changes only the global Theme image and preserves fallback/gallery sources; failed save preserves the live CSS and image. |
| 6. Lifecycle and resilience | Reset session, Restore saved CSS, Clear draft, exit choices, focus restoration, accessibility, malformed CSS, conflicts, and recovery. | Every action preserves pre-existing unsaved work; concurrent saves, selector misses, attachment failures, and navigation changes cannot silently drop edits or publish. |
| 7. Localization and qualification | Regression tests, browser matrix, translations, all four maintained TeX manuals, and hosted exact-candidate qualification. | Final tested candidate has complete required CI results and honest manual/browser gaps; no later edit is presented as qualified. |

Within one workspace, the last Custom pixel value is independent of the selected preset and travels with grouped Undo/Redo history. The preset and custom preference are editor state rather than Theme settings; re-entry derives the displayed mode from the managed CSS and saved Theme baseline, so a Custom value identical to Default or Wide can be displayed as that preset after reopening.

## Test and manual coverage plan

Extend existing focused owners instead of creating parallel save or Theme
workflows. Current contracts to retain include:

- `tests/theme_css_override_transport_test.php` and
  `tests/custom_css_preservation_test.php`: explicit save, admin/CSRF refusal,
  stale revision conflict, validation, atomic preservation, and isolation from
  preset/upload CSS and Theme settings. The transport contract also submits
  explicit Replace with PHP `UPLOAD_ERR_NO_FILE` and requires an actionable
  refusal with no CSS snapshot or file change.
- `tests/theme_custom_css_rendering_test.php` and
  `tests/theme_custom_css_browser_test.mjs`: existing editor markup, draft
  behavior, dirty-state guard, and browser workflow.
- `tests/theme_visual_css_draft_model_test.mjs`: managed-block byte preservation,
  fail-closed marker handling, value and selector admission, responsive scopes,
  safe terminal hero pseudo-element overlays and durable ownership, independent
  override preservation, idempotent reload, and immutable undo/redo snapshots.
- `tests/theme_visual_css_resize_model_test.mjs`: profile eligibility by actual
  public element and parent layout axis, vertical-only shell sizing, aspect-ratio
  bounds, CSS model-compatible changes, and invalid geometry refusal.
- `tests/theme_visual_background_workflow_test.php`: the existing explicit Save
  accepts a reviewed global Theme keep/replace/remove operation with revision
  checks, and proves stale/failed operations preserve CSS, Theme fallback mode,
  per-gallery sources and assets. Its real HTTP workflow also submits Replace
  without a selected upload field and requires an actionable refusal that leaves
  the complete saved CSS, settings, gallery metadata and background assets
  unchanged.
- `tests/custom_css_visual_preview_inspection_test.php`: typed refusal for
  unsafe stylesheet objects and inspection failures, exact 8 MiB acceptance,
  over-limit refusal, and unchanged CSS input bytes.
- `tests/public_visual_preview_policy_test.php` and
  `tests/public_visual_preview_workflow_test.php`: route/method/Admin/no-store
  policy, actual import and inspection refusals, exact-origin referrer context
  inheritance, audience preservation, public resource authorization, and
  no-write render state.
- `tests/theme_public_css_cascade_test.php`: generated Theme styles and public
  cascade, including page width and final override ordering.
- `tests/theme_upload_replacement_safety_test.php`,
  `tests/theme_original_asset_authorization_test.php`, and
  `tests/theme_asset_storage_safety_test.php`: Theme asset permissions,
  replacement safety, and file storage behavior.
- `tests/custom_css_preservation_test.php` and the composite-save contracts:
  CSS-only compatibility, stale CSS/background revisions, transaction rollback,
  and preservation of both installed assets on a failed composite save.

The disposable browser journey in `tests/support/gallery_workflow_browser.js`
uses real Admin and PHP-rendered home/gallery markup to distinguish selection
from activation, follow an actual server-rendered gallery link, style a real
heading, and preserve the managed draft through Back/Home before its explicit
background Save. After CSS Apply and successful Save, it reloads the ordinary
homepage without preview markers and checks that the computed main width, image
opacity, manual CSS and authorized Theme asset match the reviewed preview, with
no visual-editor draft style on the public document. The synthetic browser
contract provides broader interaction coverage for route allowlisting/history,
no-op inspection, stable selectors, Undo/Redo, handles, width reflow and lifecycle
controls. Tests distinguish public, unpublished, gated and private galleries and
confirm that anonymous preview does not inherit administrator access to private
media.

The final manual matrix covers Chrome and Edge desktop, Safari desktop with
trackpad gestures, mobile Safari touch, and Chrome touch. Record only observed
browser/device coverage. In particular, automated Chromium coverage does not
prove Safari trackpad or mobile touch behavior.

## Documentation and localization ownership

When user-facing behavior is implemented, keep permanent references aligned in
`README.md`, `ARCHITECTURE.md`, `CODEMAP.md`, and `TESTING.md`; record any
high-impact compatibility path in `docs/COMPATIBILITY_LIFECYCLE.md`. Update the
maintained EN, CS, DE, and SV catalogs under `app/lang/` for every new label,
tooltip, control, status, and refusal message. Update all four administrator
manual source files: `docs/PHP_Gallery_Manual.tex`,
`docs/PHP_Gallery_Manual_CZ.tex`, `docs/PHP_Gallery_Manual_DE.tex`, and
`docs/PHP_Gallery_Manual_SV.tex`. This feature does not update Patch Notes or
compiled PDFs.

## Qualification boundary

Use GitHub Actions as the authoritative qualification path described by the root
`AGENTS.md`: commit the authored source, tests, and permanent documentation to
the authorized working branch; push that exact ref; allow candidate preparation
to own generated artifacts; and inspect the exact prepared candidate's full CI
summary before claiming completion. Never directly mutate `develop` or `main`.
Report the workflow URL, branch, prepared candidate SHA, immutable comparison
base, required job results, and any manual or unavailable browser coverage.
