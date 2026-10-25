# Browser and public asset instructions

Read [the root contract](../../AGENTS.md) first. These instructions apply to
public/browser code and matching PHP markup, routes and regression expectations.
Use [app instructions](../../app/AGENTS.md) for Admin panel mutations and route
policy; use [fixture rules](../../tests/AGENTS.md) when changing browser
harnesses.
All test commands below describe coverage owned by hosted CI; root CI-first
rules
control execution.

For **every visible public or Admin UI change**, automatically read and apply
[the mandatory design contract](../../docs/ADMIN_DESIGN.md). This is required
without a user reminder. Use compact translucent media-player/WinApp surfaces,
Theme tokens, restrained rounding and compact transparent actions. Contextual
notifications/help open in a small panel beside their trigger, without a modal
backdrop. A centered blocking dialog requires an explicit task requirement.
Inspect the real desktop/narrow rendering and computed cascade before handoff;
legacy global styles and a passing source check do not prove visual compliance.
Use the approved visitor notification panel in `styles/public-shared.css` and
`Gallery\Views\view_render_gallery_visit_notifications()` as the default small
panel pattern: 42% Theme surface, readable blur, quiet line separators, small
accent icons, outlined primary action and subordinate secondary actions/note.
Read `docs/ADMIN_DESIGN.md` for the canonical values and responsive requirements.

## Imports, request lifecycle and presentation

Always document JavaScript callback parameters with JSDoc callable syntax, for
example `@param {function(number, string):void} onProgress`, and describe what
each
callback argument means in the parameter description.
Document exported preset arrays with the complete policy fields as well as
scalar
timings. Preserve native failure messages across cleanup by capturing the
message
before release and assigning the relevant feedback after release.

Whenever a revisioned module's bytes change, including formatting-only edits,
advance its owning import URL and the complete upstream import/re-export chain.
Keep source/test expectations synchronized. Use
`scripts/admin_import_contracts.php` for covered Admin relationships instead of
historical URL literals; keep route/security expectations strict.

Resolve form destinations from the prepared endpoint or `action` attribute when
named controls can shadow DOM properties. Exercise mounted and standalone forms
through the production coordinator with their fragment loaders active; assert
the
resulting public revision and retained editor nodes, not merely request
completion.

Represent presentation choices as explicit value/label entries. Reserve literal
`'page' => 'target'` maps for registered routes.

For live filters, invalidate older requests when inputs change, before debounce.
Capture the request URL once and commit rows/counts/history only for the current
response, checking again after JSON parsing. Extend the existing fixture for an
old response arriving during debounce and a stale body finishing after newer
data.

## Public Thumbnail Renderer Guidelines
Both public thumbnail renderers are permanent supported pipelines. `progressive`
is the default and safe fallback; `responsive` remains the supported legacy
renderer with complete server-rendered responsive srcsets plus no-JavaScript
behavior. Both values are permanent machine/architecture names. Do not introduce
temporary, experimental, numbered, or replacement-style renderer identifiers.

Changes to shared thumbnail bundle, bounds, HTML, manifest, warm-up,
public-card, or browser lifecycle code must test both renderers. No renderer may
weaken gallery/password/NSFW access checks, media authorization, semantic
server-rendered image markup, useful alt text, or no-JavaScript navigation.
Progressive larger candidates must remain inert until the bounded near-viewport
scheduler activates them.

The selected-gallery mode is stored as `public_thumbnail_rendering_mode` with
only `responsive` and `progressive` values. Invalid values must fall back to
progressive. Admin-facing Default/Legacy wording is UI status only and must not
leak into setting keys, PHP/JavaScript identifiers, CSS classes, filenames, data
attributes, test names, or architecture terms.

`PATCH_NOTES.md` is intentionally not changed as part of renderer implementation
work unless a separate task explicitly requests release notes.

## Public Lightbox Zoom Guidelines
Always exercise shared-link query flags through the anonymous request guard.
For native fullscreen refusal, use the production CSS in a browser and assert
decoded photographs have a nonzero stage on desktop and narrow viewports.

The public lightbox zoom is a single-viewer feature. Keep `data-lightbox-stage`,
`.lightbox-zoom-surface`, and
`data-lightbox-img` as one coordinated presentation path rather than adding a
parallel fullscreen viewer or a second
media authorization route. Scale is bounded to 100-400%. The fitted 100%
photograph is explicitly centered; the zoom
surface owns the real enlarged CSS width/height and grows around that center,
while translation-only transform moves the
surface for pan. Do not restore compositor `scale()` zoom on the image itself or
a fixed 100% clipping frame, because both
regress full-quality repaint and cursor geometry.

Pointer/pinch anchoring must be calculated from canonical fitted image
dimensions, current scale, and current translation.
Do not feed an in-flight animated `getBoundingClientRect()` back into the next
zoom step. Repeated off-center zoom must
keep the same fractional photograph point under the cursor/midpoint without
accumulating top-left or bottom-right drift.
Fullscreen must preserve both X and Y pan range and keep close/HUD controls
above the transformed media layer for hit
testing.

At exactly 100%, passive quality selection may choose a larger authorized
candidate for a large or high-DPI stage. Any
deliberate zoom above 100% must synchronously assign the already-authorized
active card `data-full-src` to the live
`data-lightbox-img` in that same input task. It must not wait for fullscreen,
resize, a debounce, detached decode,
`requestAnimationFrame`, or another mode transition. Cancel pending preview
cross-fades first, keep navigation/quality
tokens scoped to the current image, restore the protected preview on failure,
and never prefetch neighboring originals
merely because the active photo is zoomed.

After changing zoom markup, geometry, quality promotion, fullscreen CSS, or
event routing, run the seven PHP
`lightbox_zoom_*` contracts plus `node tests/lightbox_zoom_model_test.mjs`,
JavaScript syntax checks, and the manual
browser matrix in `TESTING.md`. Preserve the scoped gesture split: ordinary
mouse-wheel and two-finger trackpad scrolling pan a photo only above 100%, while
Ctrl+wheel (including Chromium trackpad pinch) zooms only the photograph. Safari
GestureEvents are the fallback for trackpad pinch; Command+wheel stays
browser-owned. At 100% keep one-finger mobile swipe navigation; above 100% allow
one-finger pan and two-finger pinch, including swipe-to-pinch and pinch-to-pan
handoff. Keep the OFP viewer independent: unmodified wheel scrolls only the
document, Ctrl+wheel/trackpad pinch zooms at the PDF cursor, touch pan/pinch
works within the document stage, and HUD colors/scale are independent of gallery
themes. Preserve maps, voting, strip/carousel controls, slideshow reset
behavior, authorized media access, and the no-JavaScript fallback.

## Public SimBrief OFP Attachment Delivery

Saved OFP PDF links use the dedicated, gallery-scoped `gallery_ofp_pdf` route.
The public gallery controller emits a same-origin `index.php` URL from the
actual script mount path rather than an absolute `base_url`; it must work on
subdirectory installations with rewrite disabled and never expose the local PDF
path or the remote SimBrief URL. The existing `media&ofp=1` form remains
compatible. Enforce `simbrief_ofp_visitor_access_allowed()` against the **source
gallery** before opening the validated local attachment: public and unpublished
galleries work for fresh anonymous visitors through their direct URLs,
access-gated galleries require the same password/share/NSFW grants, genuinely
private galleries are unavailable without the normal gallery visitor grant, and
administrator access must not leak into anonymous preview. The separate
generated page subgallery remains private. Denied, missing and invalid PDF
assets return the same no-store plain-text 404 without loading a gallery HTML
template. Treat `gallery_ofp_pdf` as a public-policy media route in both
dispatcher and authored runtime-module roots. Keep the real HTTP regression in
the central PHP test registry, and update all four permanent TeX manuals for any
future behavior changes.
