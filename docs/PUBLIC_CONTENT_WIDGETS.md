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
5. Inspect the **safe server-rendered Markdown preview** and the **illustrative** desktop/tablet/mobile placement map. The map is **not** yet a true iframe preview of the production Theme layout. Drag, click or use arrow keys to set floating custom X/Y. Shift+Arrow uses larger steps; Reset restores a valid bottom-right preset.
6. Click Save to persist. Switch publication state to Published only when content should become visible. Discard returns to the saved configuration. Duplication creates an independent **draft**, not a second automatically published overlay.
7. Test the actual public homepage/gallery with the selected Theme and a narrow viewport before calling visual appearance accepted.

No editor preview, drag, device toggle or visitor action writes a published widget. Creation/edit/reorder/delete use administrator authentication, CSRF checks and revision guards. An update from a stale editor tab is refused instead of overwriting another tab's changes.

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

The server **always** emits one safe widget article in the initial HTML, in a non-obstructive normal-flow fallback location. Visitor JavaScript may enhance that same instance to a viewport-fixed panel only when horizontal viewport size is at least 960 CSS pixels, visible height is at least 620 CSS pixels, browser zoom is within the supported range and the panel fits. A maximum of **two** panels float simultaneously. The browser geometry solver clamps them inside the usable viewport, checks protected header/primary action/back-to-top rectangles, and tries bounded nonoverlapping positions. Unsupported, oversized or conflicting panels remain in their original flow position. The implementation does not support arbitrary z-index or visitor drag-to-save.

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
- `tests/public_content_widget_floating_test.mjs` validates viewport geometry, bounds, presets/custom positions and collision failure-to-flow.
- `tests/public_content_widget_db_workflow_test.php` uses the explicitly owned disposable MySQL/MariaDB fixture to exercise real committed widget CRUD, independent PDO connections, optimistic edit/delete conflicts, safe publication state, draft duplication, and all-or-nothing transactional reorder.
- `tests/public_content_widget_localized_errors_test.php` checks the source validation error contract and safe EN/CS/DE/SV Admin error responses with preserved catalog key order.
- `tests/public_content_widget_admin_position_test.mjs` and `public_content_widget_admin_browser_test.mjs` exercise Admin pointer/keyboard/Reset and draft preservation, including an isolated Chromium page.

Run GitHub Actions candidate preparation and the full required matrix on the authorized feature branch. Check the **exact final prepared head SHA**, not an older green commit. A green earlier checkpoint does not qualify later code or documentation changes.

## Known limitations and required follow-up

The current Admin position map is illustrative, not a faithful visual inspector using the actual homepage/gallery CSS. Precise forbidden-area/collision guides, wider mobile/zoom/overlay accessibility tests, complete database-backed concurrency/permission workflows, additional locale-specific visual safety warnings, richer authored-content localization and optional image uploads remain incomplete. These are not grounds to hide authored content silently or to claim that the entire parent #126 is finished.
