# Public content widgets

Implementation and operations reference for
[#126](https://github.com/klusik/PHP_gallery/issues/126).

## Supported behavior

Administrators manage independently authored widgets through **Admin Zone →
Appearance → Widgets**. A widget is a bounded, revisioned database record with a
stable 32-character hexadecimal ID. It can contain an optional title, Markdown
body, publication state, page scope, placement, source language, width,
appearance and ordering.

The source language labels the authored title and body in public markup; it does
not translate them. Admin saves accept only the maintained English, Czech,
German and Swedish language codes. Public reads use `lang="en"` when restored or
otherwise existing data has a missing or unsupported code, so optional language
metadata cannot hide otherwise valid published content.

Typical use cases are manually curated partner gallery links, useful resources,
announcements and credits. One widget supports several links with custom
captions and normal text. The feature is **not** a federation mechanism, remote
HTML embed, advertising popup or generic page builder.

Only these source content formats are rendered: escaped paragraphs and lines,
headings, bold/italic emphasis, ordered/unordered lists and links with safe
custom labels. Internal root/site paths and HTTP(S) URLs are validated before
writing, and again during output. External links opening a new tab have
`rel="noopener noreferrer"`. Raw HTML, iframe/script injection and executable
URI schemes are inert or rejected. The current editor does **not** upload widget
images/logos.

## Administrator workflow

1. In Appearance / Widgets, create a new draft and provide a title if desired.
2. Enter Markdown content using the native formatting toolbar. For example:

   ```md
   ### Partner galleries

   Browse other galleries:

   - [Aviation gallery](https://example.org/aviation)
   - [Our featured gallery](/index.php?page=gallery&id=12)
   ```

3. Choose `Homepage`, `Gallery pages` or `Homepage and galleries`. These page
   scopes are independent of placement.
4. Select `In page` or `Floating`, then choose a supported region/preset,
   appearance and panel width.
5. Inspect the **safe server-rendered Markdown preview**, the **advisory**
   desktop/tablet/mobile positioning map, and the protected **Home or eligible
   public root Gallery Theme preview**. The selected page is used by both the
   preview iframe and the warning map. A Gallery target is offered only when a
   listed public root Gallery passes ordinary visitor access checks without an
   administrator bypass; the current visitor-level NSFW acknowledgment may
   affect availability.

   The preview iframe loads controller-prepared same-origin production routes,
   so its eligible Home or Gallery markup, Theme CSS, grid, rails and footer are
   real. Its sandbox disables public scripts and forms. The sanitized unsaved
   draft is inserted into the selected public slot and saved order without
   persisting it, including the authored-language `lang` metadata used in
   visitor markup. Saved widgets use the public sort-order and widget-ID order;
   a new draft has no persisted ID, so its order among equal sort orders is
   provisional until save.

   If Home has no gallery grid, the preview preserves the after-grid slot ahead
   of content-bottom and floating zones. A newly previewed side rail uses the
   wrapper-scoped responsive stacking behavior of the public layout.

   **Floating preview boundary:** floating placement is simulated by Admin
   against that real iframe's measured viewport and protected controls. Admin
   uses the shared geometry reader, rectangle solver, rendered widget order and
   two-panel limit; public widget scripts do not run in the sandbox. If the
   viewport is unsupported, a panel does not fit, or the solver cannot find a
   safe position, the draft remains visible in its normal-flow zone.

   Directly drag a positioned floating draft in the protected Home/Gallery
   preview, drag or click the separate positioning map, or use arrow keys to set
   the same unsaved custom X/Y fields. Shift+Arrow uses larger steps and Reset
   restores bottom-right. Dragging updates only the editor draft; it does not
   save or publish. The separate positioning map marks approximate protected
   header/control areas and up to five published peer positions. It advises on
   top-edge/clamping, likely nearby collisions, the two-panel public floating
   limit and the public tablet/mobile flow fallback. These indications are
   nonblocking and cannot guarantee exact public coordinates or widget heights.
6. Click Save to persist. Switch publication state to Published only when
   content should become visible. Discard returns to the saved configuration.
   Duplication creates an independent **draft**, not a second automatically
   published overlay.
7. Test the actual public homepage/gallery with the selected Theme and a narrow
   viewport before calling visual appearance accepted.

No editor preview, drag, device toggle or visitor action writes a published
widget. Create/edit/duplicate/reorder/delete use administrator authentication,
CSRF checks and revision guards. A stale save keeps its old revision token while
preserving the submitted draft, so another Save cannot silently rebase and
overwrite the intervening edit; use the editor's reload/discard path before
saving against the newer revision.

### Publication lifecycle

`draft`, `published` and `disabled` are separate states. Only published records
are considered for public views. Deleted records do not render; fresh migrations
create an empty widget table and no public chrome. Published records are
selected only for the allowed public home/gallery page type, after the gallery
controller has performed its established access checks. Widgets must never
expose otherwise denied unpublished/private/password-protected/NSFW gallery
resources. The content itself is public when published; do not embed private
instructions, credentials or secrets.

### Page scope and in-flow placement

| Persisted slot | Meaning |
|---|---|
| `content_top` | Immediately after site navigation, before the page's primary content; never inside the navigation/header or hero |
| `content_bottom` | After the primary public gallery content, before the footer |
| `left_rail` | True layout column beside the primary content at sufficiently wide viewports |
| `right_rail` | True layout column beside the primary content at sufficiently wide viewports |
| `home_before_grid` | Homepage immediately before the gallery grid, preserving existing controls |
| `home_after_grid` | Homepage immediately after the gallery grid |
| `footer` | Within the established global footer flow without replacing its credits |

For `all`-scope widgets using a homepage-grid position on a gallery-detail page,
the resolved location falls back to `content_bottom` once. A saved homepage-grid
slot may remain stored as an **inactive** flow preference while the widget uses
Floating mode on gallery pages. The gallery-only restriction is enforced when
selecting an **active** in-flow position, without unnecessarily discarding prior
placement configuration. Side rails are normal document-flow regions and stack
**after primary content** below 1080 CSS pixels or when the Theme-capped rail
container is at most 1140 CSS pixels wide. The width query is scoped to the rail
wrapper, so other public content and floating panels keep their existing layout
behavior. Multiple items in one region render in saved order. Empty regions emit
no wrappers.

### Floating placement and accessibility

The public placement owner also reacts to size changes of widget content and
protected controls, including an expanded Gallery picture-manager toolbar, and
to completed font loading. These changes reuse the coalesced placement solver
without waiting for a visitor scroll or window resize. Browsers without
`ResizeObserver` retain viewport/scroll recalculation and the original
server-rendered article; no content or navigation depends on observer support.

The stored mode `floating` accepts `top-left`, `top-center`, `top-right`,
`middle-left`, `middle-right`, `bottom-left`, `bottom-center`, `bottom-right`
and `custom`. Custom X/Y are normalized integer permille values from 0
through 1000. Panel width is bounded from 180 through 480 CSS pixels.

The server **always** emits one safe widget article in the initial HTML, in a
non-obstructive normal-flow fallback location. Visitor JavaScript may enhance
that same instance to a viewport-fixed panel only when horizontal viewport size
is at least 960 CSS pixels, visible height is at least 620 CSS pixels, browser
zoom is within the supported range and the panel fits. A maximum of **two**
panels float simultaneously. The browser geometry solver clamps them inside the
usable viewport, checks protected header, Home action, Gallery hero action,
back-to-top and other visible control rectangles, and tries bounded
nonoverlapping positions. Floating panels use a lower stacking layer than the
standard public lightbox. Unsupported, oversized or conflicting panels remain in
their original flow position. The implementation does not support arbitrary
z-index or visitor drag-to-save.

Floating panels include a keyboard-reachable Close button with the existing
translated public close label; it hides only the current article in the current
page view. When activation hides the focused article, focus moves without
scrolling to the next visible floating Close button in document order, wrapping
among the remaining floating controls. If none remains, focus moves to the next
visible page control after the article, wrapping to the first visible page
control when needed; when the page has no eligible control, the browser retains
its normal document focus fallback. No analytics, cookie, identity tracking or
persistent setting is involved. JavaScript disabled means all authored
links/text remain accessible in that single original HTML instance. The built-in
CSS supports long text/URLs, focus outlines, Theme text colors and responsive
sizes. Public floating geometry protects the Home search control along with the
header, Home actions, Gallery hero actions, back-to-top and other visible
controls. The registered Chromium fixture checks native Tab/Enter/Space
dismissal and focus continuity as well as standard-lightbox stacking and hit
testing; real hardware/device and broader overlay journeys remain separate
acceptance work.

## Multilingual content

Administrative controls and known field-specific validation failures are
translated using the maintained EN/CS/DE/SV catalogs. Unknown error messages
receive a translated generic fallback rather than leaking internal details.
`source_language` labels the authored widget title/Markdown. The current
database record stores **one authored version**, not four translated variants. A
visitor using another interface language sees the original authored text rather
than a fabricated translation. A per-language authored content editor or richer
fallback chain is future work; publication does not require a translation.

## Persistence and MVC ownership

| Layer | Files and responsibility |
|---|---|
| Migration | `database/migrations/202610100001_public_content_widgets.php`; append-only, empty initial table |
| Model | `app/models/public_content_widgets.php`; SQL, ordered published selection, atomic writes/reorder and revision checks |
| Service | `app/services/public_content_widgets.php`; allowlists, input normalization, safe Markdown/URL rendering, page-scope and position planner |
| Controller | `app/controllers/admin_public_widgets.php` for authorized mutation and preview; `app/controllers/public_gallery_home.php` and `public_gallery_page.php` for public view-model preparation |
| View | `app/views/admin_public_widgets.php`, `app/views/public_content_widgets.php` and the existing public gallery and layout views; no SQL |
| Browser | `public/assets/gallery-modules/admin-public-widgets.js` only enhances the Admin editor; `public-content-widgets.js` enhances already published visitor markup |
| Styles | `public/assets/styles/admin-public-widgets.css` and `public-content-widgets.css`; stable `.public-content-widget` and `data-public-widget-id` hooks |

Widgets use the ordinary installation database and should be backed up with it.
The real Theme Appearance HTTP workflow asserts that a save preserves the
complete widget-table row snapshot; application-upgrade retention remains a
separate acceptance concern. No Composer package or browser localStorage
persistence is required.

## Tests and qualification

The public placement fixture also includes runtime portrait/landscape CSS
viewport transitions, visual-viewport page-scale fallback and recovery, a native
modal dialog above a widget, and protected-toolbar expansion without a synthetic
viewport event. These are authored regression scenarios awaiting qualification
of the complete candidate. Emulated screen orientation and page scale do not
establish physical-device rotation, browser UI zoom or assistive-technology
acceptance.

Tests are registered in the existing central audit, not a feature-specific
workflow:

- `tests/public_content_widget_contract_test.php` and
  `public_content_widget_markdown_test.php` validate persistence/URL/state and
  safe formatting contracts.
- `tests/public_content_widget_public_render_test.php` verifies SSR page
  targeting, unpublished suppression, escaping, unique IDs, normal-flow
  fallback, home-to-gallery slot translation, and suppression of malformed
  persisted content on Home and Gallery.
- `tests/public_content_widget_public_browser_test.mjs` runs the registered
  Chromium placement fixture at desktop, tablet (`768x1024`) and mobile
  (`390x740`) sizes. Tablet acceptance checks that each server-rendered widget
  remains one visible in-flow instance, its content and links remain reachable
  after scrolling, and the document has no horizontal overflow. The fixture also
  checks standard lightbox stacking/hit testing, independent scrolling inside
  floating content, wheel scroll chaining to the page at both widget boundaries,
  and that a narrow Theme-capped rail wrapper stacks at a wide viewport without
  changing viewport-fixed behavior for floating widgets elsewhere in the same
  public main element.
- `tests/public_content_widget_page_composition_test.php` invokes the production
  Home/Gallery views to verify zone order, footer placement, existing content
  ordering, multiple ordered Home-slot widgets, same-anchor floating peers, both
  Gallery side rails, and no empty-plan layout wrappers.
- `tests/public_theme_width_composition_browser_test.mjs` renders the production
  Home/Gallery views, shared shell and generated Theme CSS across four
  page-width modes and seven viewport widths. Its 56 page/mode/width
  combinations check rails, content order, pagination, footer placement, long
  unbroken content and horizontal overflow. On the actual production Home view,
  it checks that the search control precedes the `home_before_grid` widget,
  exercises the real progressive search module with a typed query against a
  deterministic loopback JSON fixture through all four phases, checks the result
  and clear/reset state, and verifies a floating widget neither overlaps nor
  hit-tests above the input. It also follows the production Home pager to page
  three and verifies the selected page, replacement cards, and
  widget/pagination/grid order. The deterministic search endpoint does not
  qualify the live search controller/index or a complete installation; alternate
  Theme overrides and the full overlay/access-control matrix remain outside this
  fixture.
- `tests/public_content_widget_floating_test.mjs` validates viewport geometry,
  bounds, presets/custom positions, Gallery hero-action clearance and collision
  failure-to-flow.
- `tests/public_content_widget_db_workflow_test.php` uses the explicitly owned
  disposable MySQL/MariaDB fixture to call the updater-facing
  `run_migrations_bounded(1)` after simulating an existing widget table with a
  missing migration-ledger row. It verifies that the bounded runner records only
  that migration, reports no remaining work, and preserves representative draft,
  published and disabled rows exactly, including in-flow and floating placement
  and public visibility. A second checkpoint case rejects the migration-ledger
  insert after replay-safe DDL, checks that no false applied marker or
  widget-state change remains, removes the disposable constraint, and retries
  successfully; this new case awaits exact-candidate hosted qualification.
  Cleanup also restores the original ledger timestamp. The workflow additionally
  exercises committed CRUD, independent PDO connections, a stale edit that
  attempts to replace the winning floating placement and panel width, stale
  delete refusal, draft duplication, and all-or-nothing transactional reorder.
- `tests/public_content_widget_admin_http_workflow_test.php` exercises the real
  Theme and Widgets Admin routes against that owned fixture. It verifies a real
  Theme Appearance save changes the submitted Theme setting while preserving
  every column of every widget row; anonymous and invalid-CSRF denials for
  widget create, existing-widget save, duplicate, reorder and confirmed delete;
  authenticated no-store/read-only Markdown preview; unsafe `javascript:` link
  rejection with the submitted draft preserved; create/save redirect and reload;
  publish, disable and republish across editor reloads with matching anonymous
  Home visibility and saved-content/placement retention; no exposure of a
  published all-pages widget's content or metadata on private,
  password-protected or NSFW Gallery responses; duplicate-as-draft persistence;
  transactional reorder and stale-revision refusal; discard after a stale edit;
  and confirmed, current-revision deletion.
- `tests/gallery_workflow_browser_test.php` adds an authenticated browser
  journey against an owned disposable source-copy application: it previews one
  unsaved draft before the Home grid, checks the documented Gallery
  `content_bottom` fallback, measures desktop floating placement against Home
  and Gallery controls, and verifies tablet (`768x1024`) and mobile (`390x740`)
  in-flow fallback at their configured iframe sizes. A bounded digest confirms
  the preview leaves saved fixture rows unchanged. This does not qualify a
  deployed installation, real devices or the full Theme matrix.
- `tests/public_widget_persistence_browser_test.php` uses that shared disposable
  application and database for a separate authenticated browser journey: it uses
  the actual Markdown toolbar, switches the Admin locale through EN/CS/DE/SV
  while an unsafe formatted draft remains unsaved, confirms the translated
  Preview action label in each locale, submits the real widget form, and
  verifies the Czech validation error retains the title and exact draft without
  changing the saved fixture digest. It then corrects and saves the draft,
  clicks the real Discard link after local edits, verifies the saved draft
  returns unchanged, explicitly publishes and reloads it, and checks the
  server-rendered Home output without visitor credentials. A same-origin
  sandboxed browser frame with scripts disabled loads that exact PHP response
  and verifies the single widget remains visible in normal document flow; a
  separate frame loads it with production assets and verifies the same widget is
  fixed at desktop width and returns to visible document flow after resizing to
  mobile width. The journey fetches anonymous Home and Gallery responses, checks
  their dynamic-HTML cache headers and requires exactly one matching published
  widget in each, then logs out and navigates the browser frame through the
  actual Home and Gallery routes. On the real anonymous Gallery document it
  verifies the floating panel stays below the lightbox stage and controls during
  zoom, next-image navigation and native-pointer fullscreen entry/exit. These
  route, header and full-document checks are newly authored and await
  exact-candidate hosted qualification. It then deletes the token-owned widget
  and verifies the exact fixture digest returns to baseline. This does not
  qualify a deployed installation, real devices or the full Theme matrix.
- The persistence browser journey also exercises the standalone **Preview
  without saving** AJAX action against both the rejected unsafe draft and its
  corrected Markdown. It checks the localized Czech error and success notices,
  the expected title and Markdown rendering with formatted text and the safe
  link target, unchanged editor URL and retained Admin source language, and an
  unchanged database-state digest in both cases. This live-browser acceptance
  case awaits exact-candidate hosted qualification.
- The real Gallery lightbox load checkpoint reads the currently connected
  `data-lightbox-img` on every poll, because the viewer replaces its initial
  placeholder with a decoded display node. Success requires both natural
  dimensions to be positive. Its bounded timeout diagnostics identify
  preview/full request status and body sizes plus the current node's source and
  decode state without exposing URLs.
- `tests/public_content_widget_localized_errors_test.php` checks the source
  validation error contract and safe EN/CS/DE/SV Admin error responses with
  preserved catalog key order.
- `tests/public_content_widget_editor_localization_test.php` renders the actual
  Admin view against all four maintained catalogs and checks translated field
  labels, placement/appearance/mobile-fallback options, source-language choices
  and widget-list summaries.
- `tests/public_content_widget_missing_schema_test.php` sends synthetic
  missing-table PDO failures through the production public row reader and
  planner for Home and Gallery, checks that every public region stays empty, and
  verifies that database diagnostics do not enter public output.
- `tests/public_widget_admin_missing_schema_test.php` sends synthetic
  missing-table PDO failures through the actual Theme Widgets Admin route,
  production controller/service/model and rendered Admin view. It checks the
  safe storage message and usable empty editor, rejects raw SQL diagnostics,
  proves the GET path performs no writes, and attempts create, save and
  confirmed delete through the fixture Admin route with its valid owned CSRF
  token to verify each write fails safely and leaves no transaction open.
- `tests/public_content_widget_admin_position_test.mjs` and
  `public_content_widget_admin_browser_test.mjs` exercise Admin
  pointer/keyboard/Reset and draft preservation. The isolated Chromium fixture
  for the latter covers sandboxed Home/Gallery draft projection,
  authored-language metadata, public-order floating geometry, the rendered
  Gallery hero-action collision region, responsive iframe sizing when its Admin
  host narrows, page-scoped visitor dismissal, unsupported narrow-viewport flow
  fallback, trusted native mouse drags of the projected draft in both protected
  Home and Gallery iframe documents through the unsaved X/Y fields, a native
  primary-button pointer activation of the rendered safe Markdown link with
  prevented navigation and no Admin form submission, and a CDP-emulated touch
  drag that produces trusted touch PointerEvents while leaving the form unsaved.
  The shared fixture runner now accepts native left/middle/right mouse buttons
  and modifier bit masks. Newly authored browser assertions cover trusted
  Ctrl-click and middle-button `auxclick` behavior, empty-Home after-grid
  ordering relative to content-bottom/floating zones, wrapper-scoped rail
  behavior at wide and narrow container widths, and rail-wrapper cleanup after
  removing the last rail; these assertions await fresh exact-candidate hosted
  qualification. Physical mobile hardware remains unverified. The public
  placement Chromium fixture uses native Tab and Enter to reach and activate
  Close, checks its accessible name and visible keyboard focus, and verifies
  dismissal preserves the URL, scroll position, cookies and browser storage
  entries. These isolated Admin fixtures do not qualify the authenticated
  database workflow or the complete public Theme/browser matrix.

Run GitHub Actions candidate preparation and the full required matrix on the
authorized feature branch. Check the **exact final prepared head SHA**, not an
older green commit. A green earlier checkpoint does not qualify later code or
documentation changes.

## Known limitations and required follow-up

The Admin position map provides localized **advisory** protected-zone and
other-published-widget warnings. A sandboxed iframe with public script execution
disabled previews the real Home page or a controller-selected, publicly listed
root Gallery that passes visitor access checks without an administrator bypass.
It projects the current sanitized draft into that page's actual rendered Theme
layout and saved widget order without saving or publishing it, including the
selected authored-language `lang` metadata. Saved widgets use the public
sort-order/ID ordering; a new unsaved widget's tie position remains provisional
until it receives an ID. Floating placement in this preview uses measured iframe
geometry and the same ordered public solver. A per-document hidden probe reads
the four CSS `env(safe-area-inset-*)` values on each geometry pass; the existing
16px margin remains outside each inset, and the usable panel width shrinks by
both horizontal insets. Admin uses these same inset-adjusted bounds. Unsupported
values default to zero; a safe-area-constrained width below the existing 180px
minimum, unsupported viewport or unresolved placement keeps the widget in normal
flow.

This is not end-to-end qualification of the feature. The real HTTP workflow
covers a Theme Appearance save with complete widget-row preservation,
create/save and preview authorization/CSRF boundaries, denied anonymous and
invalid-CSRF saves of an existing record, no-store/read-only preview,
unsafe-link rejection with draft retention, PRG persistence, and a two-session
stale save that refuses changed content and publication state. It also covers
publish/disable/republish across reloads with public visibility and
saved-content/placement retention, duplicate-as-draft, reorder, discard and
confirmed delete with CSRF and revision guards. The database workflow separately
exercises independent connections and verifies that a stale submission changing
placement and panel width cannot replace the winner's committed row. The
authenticated browser journey covers one unsaved draft delivered into
Home/Gallery preview iframes, desktop floating clearance from rendered controls,
and configured tablet/mobile iframe sizes with in-flow fallback; a bounded
digest verifies the preview leaves saved rows unchanged. A separate
authenticated browser journey saves and reloads a draft, changes title/content
locally and clicks the real Discard link, verifies that the saved draft and
widget-state digest are restored, explicitly publishes and reloads it, and
checks the server-rendered Home output without visitor credentials. It loads
that exact anonymous response first into a same-origin frame whose sandbox
disables scripts and verifies that the article remains visible in normal flow,
then into a separate browser document with production assets and verifies
desktop floating enhancement plus mobile in-flow fallback for the same single
article. The journey also checks anonymous Home/Gallery HTML cache headers and
widget uniqueness, then logs out and navigates to the actual public Home and
Gallery routes; the real Gallery document exercises the production lightbox,
zoom, next-image, native-pointer fullscreen and stacking behavior. These new
route and header assertions await hosted qualification. It deletes the widget
and verifies fixture state returns to baseline. Both journeys use an owned
disposable source-copy application; they do not qualify a deployed installation
or real devices. The isolated Admin Chromium fixtures still substitute local
Home/Gallery markup and routes. Broader live iframe/browser integration and the
complete public Theme/device matrix remain open across Theme widths and
overrides, header/rails/grid/footer combinations, real devices and browser zoom,
broader native-dialog/access-control journeys, broader keyboard coverage,
assistive technology and physical touch acceptance. The public placement fixture
checks native Tab, Enter and Space activation of the floating widget's Close
button, its accessible name and visible focus outline, focus continuity to the
next floating Close control or a page navigation control, preservation of URL,
scroll position, cookies and browser storage entries, and single-article
dismissal; it also checks native wheel scrolling inside floating content, page
scroll chaining at both widget boundaries, standard-lightbox stacking/hit
testing and safe-area bounds, but not the complete overlay matrix. The isolated
missing-schema regressions cover fail-closed public reads/planning, the actual
Theme Widgets Admin GET route/view and create/save/delete POST attempts against
synthetic missing widget storage. These Admin requests verify the safe error and
private SQL diagnostics, check that a failed create rolls back, and assert no
request leaves a transaction open. The database workflow now verifies mixed
draft, published and disabled row retention when the table exists but its
migration marker is missing, and the new checkpoint-failure case checks that
replay-safe DDL can be retried without a false ledger marker or widget-state
change; that new case awaits exact-candidate qualification. Provider-level
backup/restore and CMS upgrade scenarios remain open. The Admin map and warnings
now use measured viewport geometry and the production solver's 12px clearance
rule; the protected iframe simulation does not replace broad live-page or
real-device acceptance. Richer authored-content localization and optional image
uploads remain future work. Final candidate preparation and hosted CI on the
exact final prepared branch head are still required. These gaps remain part of
the ten open child issues under #126; this increment does not complete the
parent issue.
