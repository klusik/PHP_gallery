# Testing Guide

## Purpose
This project is a plain PHP gallery CMS without a formal browser automation stack. The most reliable testing approach is a mix of fast syntax checks, focused script-level checks, and a repeatable manual smoke-test scenario that exercises the core gallery lifecycle.

## Test Layers

### 1. Syntax Checks
Use these first when touching PHP or JavaScript:

```bash
php -l path/to/file.php
```

For JavaScript, use whatever local parser or linter is available in your environment. Syntax checks catch obvious breakage, but they do not prove the app still behaves correctly.

### 2. Script-Level Tests
The repository uses current direct PHP regression tests under `tests/`. Run the complete isolated suite with:

```bash
php tests/run.php
```

Run one focused test directly when diagnosing a failure:

```bash
php tests/gallery_visibility_model_test.php
php tests/duplicate_photo_detector_test.php
php tests/duplicate_photo_ledger_test.php
php tests/browser_upload_settings_test.php
php tests/gallery_public_paths_test.php
php tests/migration_consistency_test.php
php tests/migration_legacy_runner_compatibility_test.php
php tests/database_maintenance_test.php
php tests/database_maintenance_schema_repair_test.php
php tests/updater_safety_model_test.php
php tests/thumbnail_warmup_model_test.php
php tests/public_thumbnail_rendering_model_test.php
php tests/public_thumbnail_markup_test.php
php tests/hero_tag_theme_model_test.php
php tests/tag_metadata_mysql_compatibility_test.php
node tests/progressive_thumbnail_renderer_test.mjs
```

The favorite shortcut test covers zero configured shortcuts, direct gallery links, the optional main-page shortcut, duplicate/missing-gallery cleanup, public visibility filtering, and HTML escaping.
The gallery dates test covers manual date range normalization, reversed-range rejection, public display formatting with en dash separators, rendered date attributes, and branch matching used by scoped EXIF suggestion reviews.
The duplicate photo detector tests cover exact checksum matches, normalized EXIF candidates, file-size-only rejection, selected-branch/global scope, deterministic and bounded pair expansion, persistent pair/exact-gallery filtering, parent/child gallery independence, clickable public context links, delete and ledger scope validation, detector-job pruning, database migration contracts, reuse of the existing image deletion service, and in-place AJAX side-panel integration for delete/ignore/clear actions. The ledger test separately covers canonical pair storage, per-administrator keys, exact-gallery semantics, cascade constraints, current-admin clearing, and protected maintenance policy.
The gallery public-path test covers Czech transliteration, decomposed accents, invisible Unicode characters, HTML entities, hierarchical paths, and sibling slug collisions.
The tag metadata MySQL compatibility test guards the Admin tag-usage query against MySQL error 3065 by requiring every DISTINCT ordering expression that is not already projected to be included in the SELECT list.
The migration consistency test validates every migration definition, preflights the complete migration set, and proves that old schema_migrations rows remain harmless after obsolete migration files are removed.
The legacy migration-runner compatibility test verifies that PHP repair migrations work both with the current definition-aware runner and with the former SQL-only runner that may still be present during a partial patch deployment.
The database maintenance test covers information_schema normalization, compact and legacy schema detection, SQL-literal reference scoping, obsolete thumbnail objects, orphan and expiry rules, deterministic duplicate survivor selection, protected content/log/telemetry tables, report-only unsupported thumbnail variants, Admin authentication, CSRF, confirmation contracts, and the absence of filesystem cleanup side effects.
The Admin log scaling test covers indexed age/grouping migration contracts, grouped browsing, bounded keyset exports, retention normalization, and the archive-first deletion boundary. The Admin log archive maintenance test covers protected day archive paths, self-describing JSON/HTML output, row-count verification, interrupted-work recovery, resumable state, and retention cleanup without exposing archive data publicly.
The database maintenance schema-repair test uses a mutable PDO fixture to verify audit-table creation, absent thumbnail tables, partially compacted schemas, geometry migration before destructive DDL, obsolete index/foreign-key cleanup, already compact schemas, idempotent retry, and the absence of row or filesystem deletion.
The updater safety test verifies that critical runtime files are required before deployment starts and that valid top-level app entries such as `app/views.php`, `app/views/`, `app/lang/`, and migration support modules are never classified as misplaced project copies.

The public thumbnail rendering model test covers responsive default/fallback normalization, supported setting persistence, invalid Admin input normalization, the narrow renderer dispatch boundary, the unchanged responsive eager/lazy/fetchpriority thresholds, and progressive small-thumbnail thresholds. The public thumbnail markup test covers complete responsive srcsets, small-only progressive active srcsets, inert larger candidates, WebP/JPEG structures, missing variants, synthetic bounds, intrinsic dimensions, media fallback, warm-up attributes, and selected-gallery NSFW gate ordering. The hero tag Theme model test covers 20-tag and five-row defaults, server-side clamping, display-all and scrollbar booleans, usage/alphabetical mode normalization, Admin persistence wiring, complete server-rendered hero groups, full-width CSS overrides, anonymous/logged-in browser entrypoints, accessible disclosure state, row-based scrollbar activation, and English/Czech public strings. `tests/progressive_thumbnail_renderer_test.mjs` covers browser-independent candidate parsing, smallest-adequate selection, capped DPR width calculation, queue deduplication, visible priority, and the two-worker concurrency bound. DOM intersection, actual browser network order, decode timing, cache reuse, lightbox/maps/votes interaction, hero tag wrapping at real browser widths, and reduced-motion rendering remain manual checks.

These tests are maintained against the current namespaced production code. They are best for pure logic, helper functions, and regression checks that do not require a browser session. A release patch should not be published while `php tests/run.php` reports a failure.

### 3. Manual Functional Smoke Tests
For feature work, use the same end-to-end scenario every time. Keep one dedicated test installation or local database so you can create and remove test content freely.

Recommended flow:

1. Log in as admin.
2. Open the dashboard and confirm it renders without errors.
3. Create a new gallery.
4. Edit gallery title, description, manual date range, visibility, tags, and ordering settings.
5. Upload 2 to 3 images.
6. Open the gallery on the public site.
7. Open an image in the lightbox.
8. Reorder images if the change touches ordering.
9. Open an existing gallery that contains photos with EXIF dates, use **Apply to this gallery**, and confirm the From/To fields update without a full page reload when JavaScript is enabled. Repeat from any side-panel editor entry point that exposes the same component. Then confirm the gallery card displays the resulting branch range with the en dash separator.
10. Open **Review branch suggestions** for a parent gallery and confirm the table only lists that gallery and its subgalleries.
11. Open Admin **Gallery dates** after scanning images with EXIF dates, then apply one suggestion and confirm the gallery card displays the resulting date range.
12. Rename or move the gallery if the change touches file or path logic. Confirm the public URL uses lowercase ASCII slugs, contains no encoded spaces or diacritics, and still resolves after moving the gallery under another parent.
13. Create a gallery named **Testovací fotky** with a child named **Test nahrání** and confirm the child URL is `/gallery/testovaci-fotky/test-nahrani/`.
14. Delete the test gallery and confirm cleanup succeeds.


### Public Thumbnail Rendering Smoke Test

Use a gallery with enough photos to create several viewport lengths. Test with browser DevTools, an empty cache, and a simulated slow connection. Perform the checks both anonymously and while logged in.

1. Leave Admin > Theme > Layout > Public thumbnail rendering on **Responsive browser selection - Default**. Confirm a missing/fresh setting also selects this mode and that switching modes requires no cache or data migration.
2. In the Elements panel, confirm responsive photo cards contain server-rendered `<picture>/<img>` markup and expose their complete available bounded WebP/JPEG `srcset` immediately. The `src` should prefer the 300 px derivative when available.
3. In Network, reload with an empty cache. Confirm the browser directly requests the candidate it selects from the responsive set rather than first requiring JavaScript to discover the image. Native browser behavior may choose a larger candidate immediately.
4. Switch to **Progressive thumbnail sharpening - Beta**. Before browser activation, confirm the live `srcset` contains only the small candidate and larger candidates appear only in `data-progressive-srcset`.
5. Reload with an empty cache and slow throttling. Confirm the small request begins first. Larger requests must begin only after the small image is loaded and only for visible or approximately 720 px near-visible cards. Scroll slowly and verify far-offscreen cards remain unupgraded.
6. In Network, verify no more than 2 progressive larger preload/decode jobs are active at once. Visible cards should overtake merely near-visible queued cards when both are waiting.
7. Watch one sharpening card under heavy throttling. The small image must remain visible until the replacement loads/decodes. Force a larger request to fail and confirm the small image remains functional. No fake percentage indicator should appear.
8. Resize the window and change device emulation DPR. Relevant cards may upgrade further when a larger candidate is required, but they must not downgrade, loop indefinitely, or repeatedly download an already adequate candidate.
9. Check for accidental double downloads by filtering Network to one photo basename. In progressive mode, one small transfer plus at most the needed larger replacement is expected. Repeated requests for the same larger URL after resize/reinitialization indicate a regression. Responsive mode should not perform the progressive small-then-large sequence intentionally.
10. Disable JavaScript and reload progressive mode. Confirm small thumbnails, direct photo/gallery links, alt text, layout, password/access behavior, and navigation still work. Responsive mode must remain fully functional too.
11. Confirm stable card dimensions before decode. Stored intrinsic width/height should be present when known, and the existing thumbnail background should paint the slot without shifting surrounding cards.
12. Open the lightbox, vote on an image, use photo maps where available, search/navigate normally, and exercise thumbnail warm-up fallback. These features must behave identically in both modes.
13. Verify restricted NSFW cards and inaccessible/password-protected galleries do not expose protected thumbnail/media URLs through progressive data attributes.
14. Enable `prefers-reduced-motion: reduce`. The progressive renderer introduces no required pulse/shimmer animation; the card remains static while sharpening occurs.
15. Compare perceived readiness rather than claiming total bytes are lower. Progressive mode can transfer both the small image and a larger replacement, so record transfer totals separately from first useful paint/interaction observations.

The browser/network observations above are manual verification only. The standalone PHP and Node tests do not claim coverage of real browser request scheduling or visual decode behavior.

### Gallery Hero Tag Theme Smoke Test

Use a gallery with more than 20 direct and/or contained tags, including several tags with deliberately different assignment frequencies. Perform the public checks both anonymously and while logged in, because the two render pipelines use different browser entrypoints.

1. Open **Admin > Theme > Appearance > Gallery tags**. With a fresh installation or missing settings, confirm **Most used first** is selected, **Display every tag immediately** is off, the initial tag limit is 20, scrollbar support is enabled, and its row threshold is 5.
2. Move the visible-tag slider and confirm the exact number field follows it. Edit the number field and confirm the slider follows it. Repeat for the scrollbar-row slider and number field. Values must remain within 1 to 200 tags and 1 to 12 rows.
3. Enable **Display every tag immediately** and confirm the initial-limit controls are hidden because they no longer affect public disclosure. Disable it and confirm the controls return with the previous value. Disable the scrollbar and confirm its row controls are hidden; re-enable it and confirm the saved row value remains available.
4. Save the Theme page, reload it, and confirm all five values persist. No database migration should be required because the settings use `app_settings`.
5. Open the tagged public gallery at desktop width. Confirm the hero tag panel itself and each tag list use the full available hero width. Tags must continue wrapping toward the right edge rather than stopping at the normal readable paragraph width.
6. With the default 20-tag limit and more than 20 available tags, confirm only the first 20 tags are visible after JavaScript initializes and **Display all tags** appears. Click it and confirm all already-rendered tags appear immediately with no page navigation, reload, XHR, or fetch. The button must change to **Show fewer tags** and expose `aria-expanded="true"`. Collapse again and confirm the content returns to the configured limit.
7. When the collapse boundary falls before all tags in the contained-tag group, confirm a **Containing tags** label is hidden if that group has no visible tag. Expand the collection and confirm the label returns with its tags.
8. Switch to **Alphabetical**, save, and verify each semantic group is alphabetically ordered. Switch back to **Most used first** and verify tags with more direct gallery plus photo assignments appear first within their own group; equal counts should be alphabetical. Direct gallery and contained groups must not be merged together.
9. Resize the browser until tags wrap across different numbers of lines. With the scrollbar enabled and its threshold set low enough to trigger, confirm scrolling appears only after the measured visual row count exceeds the configured threshold. Resize wider so the rows fall below the threshold and confirm the internal scrollbar disappears automatically. Disable the scrollbar setting and confirm the hero grows naturally at all widths.
10. Disable JavaScript and reload the gallery. Confirm every tag is visible, usable and server-rendered, and the progressive disclosure control stays hidden. Re-enable JavaScript and repeat anonymously plus in the logged-in public view.
11. Run `php tests/hero_tag_theme_model_test.php`, JavaScript syntax checks for `hero-tags.js`, `theme-form.js`, both public entrypoints, and then the full `php tests/run.php` suite.

### Duplicate Photo Detector Smoke Test

1. Apply pending migrations, including `202608080001_duplicate_photo_ledger.php`, then log in as an administrator and open a gallery containing prepared duplicate photos across the selected gallery and one or more nested subgalleries.
2. Open **Find duplicate photos** from the gallery Images section and confirm it uses the existing right-side Admin panel rather than a second modal or standalone route.
3. Confirm **Search all galleries** is unchecked on a fresh detector view. Run local and explicit global scans and verify the scope labels and bounded AJAX progress while the panel remains open.
4. Verify exact SHA-256 and normalized-EXIF possible matches still behave as specified, including different file sizes for valid EXIF candidates and rejection of size-only matches.
5. Confirm completed findings are rendered as deterministic left/right pairs. Verify each side shows image id, filename, file size, dimensions/MIME where stored, EXIF/camera/lens context, and matching signals.
6. Click each gallery title/path and verify it opens the correct public gallery in a new tab. Click each preview, filename, and gallery-relative path and verify it opens the correct public photo context in a new tab. The Admin page and detector panel must remain unchanged.
7. Click **Ignore this pair from now on** on one finding. Verify the action completes through AJAX with no reload/navigation, the right-side panel remains open, the pair disappears immediately, and the ledger count increases.
8. Start a new duplicate search with the same administrator and verify the ignored pair is not shown again while other relationships from the same source group remain eligible.
9. On a left/right pair from different galleries, click **Ignore all from this gallery** on only one side. Verify all currently displayed/future pairs involving that exact gallery are suppressed. Verify a parent or child gallery with a different gallery id is not suppressed automatically.
10. Repeat the exact-gallery action from the opposite side of another pair to confirm left/right controls are independent and the server derives the stored gallery from the submitted result image rather than a browser-provided gallery id.
11. Use **Clear ledger**. Verify it runs through AJAX, the panel stays open, counts return to zero, and a new search can show previously ignored pair/gallery findings again.
12. Confirm ledger decisions are per administrator by testing with a second administrator account when available. One account's ignored pairs/galleries must not suppress another account's results.
13. On a disposable duplicate, press **Delete this** once. Confirm there is no confirmation dialog, no reload/navigation, the browser URL stays unchanged, the panel remains open, and refreshed pair counts/results reflect the deletion.
14. Confirm deletion reuses the existing gallery image mutation semantics for original files, image rows, derivatives, cover references, path safety, and Admin logging. Repeat for a nested subgallery and global-search result.
15. Confirm forged/stale pair IDs, image IDs, moved images outside an immutable local scope, and missing/expired detector jobs are rejected server-side.
16. Disable JavaScript or use the detector route directly and verify normal POST/redirect forms still work as fallback for scan continuation, ledger actions, and explicit deletion. This fallback is not the expected JavaScript interaction path.
17. Run `php tests/duplicate_photo_detector_test.php`, `php tests/duplicate_photo_ledger_test.php`, `php tests/migration_consistency_test.php`, and the full `php tests/run.php` suite.


## What To Retest After A Change

### Low-Risk Changes
For CSS, text, layout polish, or small UI tweaks, test the affected page and one nearby page.

### Medium-Risk Changes
For controllers, forms, admin tools, or display logic, retest the touched feature plus the main gallery browse flow.

### High-Risk Changes
For routing, permissions, uploads, deletes, renames, migrations, public media serving, or feature flags, retest:

- admin login
- gallery create/edit/delete
- photo upload and public rendering
- lightbox and navigation
- any route or tool the change can disable
- schema migration or install flow, if database code changed

## Practical Rule
Ask these questions before and after the change:

- Can an admin still manage galleries?
- Can visitors still browse public galleries?
- Can media still upload, render, and delete?
- Did I touch a route, permission check, or database schema?
- If an action starts in the Admin right-side panel, does the JavaScript path keep the panel open and avoid page navigation/reload?

If the answer is yes to any of those, run the manual smoke test in addition to syntax checks.

For side-panel work, full-page POST/redirect behavior is a fallback test, not the expected JavaScript behavior. Test the in-panel path first. A panel action should update its fragment or affected page elements in place. If the feature is specified as one-click, also verify that no unrequested `window.confirm()` or other intermediate prompt was introduced.
For persistent side-panel mutations such as ignore/review ledgers, verify every action through the JavaScript path first: the request must ask for JSON, the browser URL must not change, the panel shell must stay open, only owned fragments/page elements may refresh, and controls rendered by the replacement fragment must still be intercepted by delegated handlers.

## Recommended Habit
Keep a short test note for each significant change:

- what changed
- what you tested
- what you did not test
- any warning signs or follow-up work

That makes regressions easier to track and helps future changes focus on the highest-risk paths first.

### Duplicate Photo Detector side-panel deletion

1. Open a gallery while authenticated as an administrator and launch **Find duplicate photos** from the existing right-side Admin panel.
2. Complete a scan that contains at least one duplicate group.
3. Click **Delete this** once and verify no browser confirmation dialog appears.
4. Verify the delete request starts immediately through AJAX, the browser URL does not navigate to the standalone Admin Duplicate Photo Detector page, no full-page reload occurs, and the right-side panel remains open.
5. Verify only the detector fragment refreshes in place, the deleted photo disappears immediately, and a group with only one surviving member is removed.
6. Repeat from a result belonging to a nested subgallery and, separately, from **Search all galleries** scope.
7. Refresh the underlying gallery afterward and verify the deleted image remains deleted and no unrelated image was removed.
