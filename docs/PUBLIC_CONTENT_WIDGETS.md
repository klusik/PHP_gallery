# Public content widgets

Implementation and operations reference for [#126](https://github.com/klusik/PHP_gallery/issues/126).

## Supported behavior

Administrators manage independently authored widgets through **Admin Zone → Appearance → Widgets**. A widget is a bounded, revisioned database record with a stable 32-character hexadecimal ID. It can contain an optional title, Markdown body, publication state, page scope, placement, source language, width, appearance and ordering.

Typical use cases are manually curated partner gallery links, useful resources, announcements and credits. One widget supports several links with custom captions and normal text. The feature is **not** a federation mechanism, remote HTML embed, advertising popup or generic page builder.

Only these source content formats are rendered: escaped paragraphs and lines, headings, bold/italic emphasis, ordered/unordered lists and links with safe custom labels. Internal root/site paths and HTTP(S) URLs are validated before writing, and again during output. External links opening a new tab have `rel="noopener noreferrer"`. Raw HTML, iframe/script injection and executable URI schemes are inert or rejected. The current editor does **not** upload widget images/logos.

## Administrator workflow

1. In Appearance / Widgets, create a new draft and provide a title if desired.
2. Enter Markdown content using the native formatting toolbar. For example:

   ```md
   ### Partner galleries

   Browse other galleries:

   - [Aviation gallery](https://example.org/aviation)
   - [Our featured gallery](/index.php?page=gallery&id=12)
   ```

3. Choose `Homepage`, `Gallery pages` or `Homepage and galleries`. These page scopes are independent of placement.
4. Select `In page` or `Floating`, then choose a supported region/preset, appearance and panel width.
5. Inspect the **safe server-rendered Markdown preview**, the **advisory** desktop/tablet/mobile positioning map, and the protected **Home or anonymous-accessible public root Gallery Theme preview**. The page selector chooses the public page used by both the iframe and the warning map. The iframe loads controller-prepared, same-origin visual-preview routes and retains that page's rendered markup, Theme CSS, grid/rails and footer. Its sandbox disables public scripts and forms. It inserts the sanitized unsaved draft into the selected public slot and saved order without saving it. Saved widgets use the same sort-order and widget-ID order as the public runtime. A new unsaved widget has no persisted ID yet, so its position among widgets with equal sort order is provisional until save assigns an ID. For floating drafts, Admin measures that iframe's viewport and protected controls and applies the same geometry reader, rectangle solver, rendered widget order and two-panel limit as the public runtime. If the viewport is unsupported, a panel does not fit, or the solver cannot find a safe position, the widget remains visible in its normal-flow zone. A Gallery target is supplied only when a listed public root Gallery passes ordinary visitor access checks without an administrator bypass; the current visitor-level NSFW acknowledgment may affect availability. Drag, click or use arrow keys to set floating custom X/Y; Shift+Arrow uses larger steps and Reset restores bottom-right. The separate positioning map still marks approximate protected header/controls areas and up to five published peer positions. It advises on top-edge/clamping, likely nearby collisions, the two-panel public floating limit and the public tablet/mobile flow fallback. These indications are nonblocking and cannot guarantee exact public coordinates or widget heights.
6. Click Save to persist. Switch publication state to Published only when content should become visible. Discard returns to the saved configuration. Duplication creates an independent **draft**, not a second automatically published overlay.
7. Test the actual public homepage/gallery with the selected Theme and a narrow viewport before calling visual appearance accepted.

No editor preview, drag, device toggle or visitor action writes a published widget. Create/edit/duplicate/reorder/delete use administrator authentication, CSRF checks and revision guards. A stale save keeps its old revision token while preserving the submitted draft, so another Save cannot silently rebase and overwrite the intervening edit; use the editor's reload/discard path before saving against the newer revision.

### Publication lifecycle

`draft`, `published` and `disabled` are separate states. Only published records are considered for public views. Deleted records do not render; fresh migrations create an empty widget table and no public chrome. Published records are selected only for the allowed public home/gallery page type, after the gallery controller has performed its established access checks. Widgets must never expose otherwise denied unpublished/private/password-protected/NSFW gallery resources. The content itself is public when published; do not embed private instructions, credentials or secrets.

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

For `all`-scope widgets using a homepage-grid position on a gallery-detail page, the resolved location falls back to `content_bottom` once. A saved homepage-grid slot may remain stored as an **inactive** flow preference while the widget uses Floating mode on gallery pages. The gallery-only restriction is enforced when selecting an **active** in-flow position, without unnecessarily discarding prior placement configuration. Side rails are normal document-flow regions and stack **after primary content** below 1080 CSS pixels. Multiple items in one region render in saved order. Empty regions emit no wrappers.

### Floating placement and accessibility

The stored mode `floating` accepts `top-left`, `top-center`, `top-right`, `middle-left`, `middle-right`, `bottom-left`, `bottom-center`, `bottom-right` and `custom`. Custom X/Y are normalized integer permille values from 0 through 1000. Panel width is bounded from 180 through 480 CSS pixels.

The server **always** emits one safe widget article in the initial HTML, in a non-obstructive normal-flow fallback location. Visitor JavaScript may enhance that same instance to a viewport-fixed panel only when horizontal viewport size is at least 960 CSS pixels, visible height is at least 620 CSS pixels, browser zoom is within the supported range and the panel fits. A maximum of **two** panels float simultaneously. The browser geometry solver clamps them inside the usable viewport, checks protected header, Home action, Gallery hero action, back-to-top and other visible control rectangles, and tries bounded nonoverlapping positions. Unsupported, oversized or conflicting panels remain in their original flow position. The implementation does not support arbitrary z-index or visitor drag-to-save.

Floating panels include a keyboard-reachable Close button with the existing translated public close label; it hides only the current article in the current page view. No analytics, cookie, identity tracking or persistent setting is involved. JavaScript disabled means all authored links/text remain accessible in that single original HTML instance. The built-in CSS supports long text/URLs, focus outlines, Theme text colors and responsive sizes. Real hardware/device and complex stacking tests are still a separate acceptance task.

## Multilingual content

Administrative controls and known field-specific validation failures are translated using the maintained EN/CS/DE/SV catalogs. Unknown error messages receive a translated generic fallback rather than leaking internal details. `source_language` labels the authored widget title/Markdown. The current database record stores **one authored version**, not four translated variants. A visitor using another interface language sees the original authored text rather than a fabricated translation. A per-language authored content editor or richer fallback chain is future work; publication does not require a translation.

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

Widgets use the ordinary installation database and should be backed up with it; Theme saves and application upgrades must not delete the table or records. No Composer package or browser localStorage persistence is required.

## Tests and qualification

Tests are registered in the existing central audit, not a feature-specific workflow:

- `tests/public_content_widget_contract_test.php` and `public_content_widget_markdown_test.php` validate persistence/URL/state and safe formatting contracts.
- `tests/public_content_widget_public_render_test.php` verifies SSR page targeting, unpublished suppression, escaping, unique IDs, normal-flow fallback and home-to-gallery slot translation.
- `tests/public_content_widget_page_composition_test.php` invokes the production Home/Gallery views to verify zone order, footer placement, existing content ordering, and no empty-plan layout wrappers.
- `tests/public_content_widget_floating_test.mjs` validates viewport geometry, bounds, presets/custom positions, Gallery hero-action clearance and collision failure-to-flow.
- `tests/public_content_widget_db_workflow_test.php` uses the explicitly owned disposable MySQL/MariaDB fixture to exercise real committed widget CRUD, independent PDO connections, optimistic edit/delete conflicts, safe publication state, draft duplication, and all-or-nothing transactional reorder.
- `tests/public_content_widget_admin_http_workflow_test.php` exercises the real Theme Widgets Admin route against that owned fixture: anonymous and invalid-CSRF denials, authenticated no-store/read-only Markdown preview, unsafe `javascript:` link rejection with the submitted draft preserved, create/save redirect and reload, and a stale save from a second cookie-isolated Admin session.
- `tests/public_content_widget_localized_errors_test.php` checks the source validation error contract and safe EN/CS/DE/SV Admin error responses with preserved catalog key order.
- `tests/public_content_widget_editor_localization_test.php` renders the actual Admin view against all four maintained catalogs and checks translated field labels, placement/appearance/mobile-fallback options, source-language choices and widget-list summaries.
- `tests/public_content_widget_missing_schema_test.php` sends synthetic missing-table PDO failures through the production public row reader and planner for Home and Gallery, checks that every public region stays empty, and verifies that database diagnostics do not enter public output.
- `tests/public_content_widget_admin_position_test.mjs` and `public_content_widget_admin_browser_test.mjs` exercise Admin pointer/keyboard/Reset and draft preservation. The isolated Chromium fixture for the latter covers sandboxed Home/Gallery draft projection, public-order floating geometry, the rendered Gallery hero-action collision region, responsive iframe sizing when its Admin host narrows, page-scoped visitor dismissal and unsupported narrow-viewport flow fallback. Its local route handlers and fixture markup do not qualify browser-driven Admin/iframe delivery, database lifecycle or the complete public Theme/browser matrix.

Run GitHub Actions candidate preparation and the full required matrix on the authorized feature branch. Check the **exact final prepared head SHA**, not an older green commit. A green earlier checkpoint does not qualify later code or documentation changes.

## Known limitations and required follow-up

The Admin position map provides localized **advisory** protected-zone and other-published-widget warnings. A sandboxed iframe with public script execution disabled previews the real Home page or a controller-selected, publicly listed root Gallery that passes visitor access checks without an administrator bypass. It projects the current sanitized draft into that page's actual rendered Theme layout and saved widget order without saving or publishing it. Saved widgets use the public sort-order/ID ordering; a new unsaved widget's tie position remains provisional until it receives an ID. Floating placement in this preview uses measured iframe geometry and the same ordered public solver; unsupported viewports or unresolved placement retain normal flow.

This is not end-to-end qualification of the feature. The new real HTTP workflow covers the widget route's create/save and preview authorization/CSRF boundary, preview no-store/read-only response, unsafe-link rejection with draft retention, PRG persistence after reload, and a two-session stale-save refusal. It does not yet cover browser-driven Admin-to-iframe delivery or every persistent mutation route (duplicate, reorder, disable and delete) over HTTP. The checked-in Chromium fixture substitutes local Home/Gallery markup and routes; live iframe/browser integration and the complete public Theme/browser matrix remain open across Theme widths and overrides, header/rails/grid/footer combinations, narrow devices and browser zoom, lightbox/overlay interactions, keyboard focus, touch input and assistive technology. The isolated missing-schema regression covers fail-closed public reads and planning for a synthetic missing-table exception; real migration retry and Admin behavior, CMS/Theme upgrade, backup and restore scenarios remain open. The map is still illustrative rather than pixel-accurate; the iframe's floating simulation does not replace broad live-page or real-device acceptance. Richer authored-content localization and optional image uploads remain future work. Final candidate preparation and hosted CI on the exact final prepared branch head are still required. These gaps remain part of the ten open child issues under #126; this increment does not complete the parent issue.
