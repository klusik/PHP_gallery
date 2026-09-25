# Gallery editor render fix (2026-09-25)

The user still saw no Save button and only a URL hash change when clicking API or another tab, both in the right-side panel and Admin. Their live DOM check on the open panel showed eight tab controls, zero tab panels, and no save bar; the JavaScript tabs were bound, but the response lacked the rest of the form.

The PHP error log at the same time reported `Call to undefined function Gallery\Views\t()` in `app/views/admin_gallery_edit_tabs.php:184`. Rendering stopped while building Identity's advanced-settings label, before any tab panel or save bar was emitted. The missing `use function Gallery\Services\t;` import is now fixed. `tests/gallery_edit_concurrency_test.php` renders the real Identity view inside the shared form so this fatal error fails the regression suite.

The user then confirmed that tabs work but the Save gallery bar floated across fields and disappeared on some tabs. The dialog animation in `public/assets/styles/admin-cinematic.css` left a permanent identity `transform`, so the `position: fixed` bar stayed attached to the scrolling dialog and moved upward with it. The open-state transform is now `none`; after the short entrance animation, the bar stays at the viewport bottom. The gallery editor keeps Save gallery available on every tab and reserves bottom space for it; its help text identifies the four settings tabs that the shared form saves. The browser fixture now loads the cinematic stylesheet and checks the bar position after scrolling and its presence on API. The focused disposable Chromium regression passed with 107 assertions.

The quick audit and final full audit passed after these fixes (235 PHP regressions passed, 7 database-fixture skips, 24 Node, 36 WinApp, and 5 Chromium browser integrations passed). The core manifest was regenerated and checked. The user reloaded the live gallery and confirmed that API switches content, Uložit stays visible on API, and the bar remains at the bottom while scrolling. Do not attribute this specific failure to CSS, a stale browser asset, or an incorrect Apache checkout; the access log confirmed current assets and the PHP error explains the truncated response.

---
# Gallery editor follow-up (2026-09-25)

The user confirmed that the name-only `+` step, saved defaults, and SimBrief updates work, but reported two remaining editor defects: no reachable **Save gallery** button and tab clicks (API, Access, etc.) that do not switch content. Keep the name-only creation flow and all editor controls in the right-side panel.

Analysis found that the shared form's save bar is rendered after four long settings panels. Its former sticky positioning did not make it visible while editing near the top of the drawer. The drawer now needs a visible bottom save bar on Identity, Access, Display, and Media, while tabs with their own actions should not show that unrelated save action.

A browser hit-target regression reproduced the tab failure with a wrapped gallery title and scrolled drawer: the sticky header covered the API tab because the tab strip used a fixed `top: 4.65rem` offset. The fix must use the measured header height, including later title wrapping, and preserve the open drawer and public URL when switching tabs. The previous programmatic-click fixture missed this physical overlap; keep the realistic browser regression.

The previous name-only workflow and saved-default implementation was committed as `9faab10`. This follow-up adds the visible drawer save bar, header-height-aware sticky tabs, and a realistic browser regression for creation, tab switching, save-bar visibility, and the unchanged public URL. These follow-up changes are not committed. The quick audit passed all 11 suites; the full audit is the final handoff gate. The older status notes below are historical snapshots.

---
# Current gallery workflow direction (2026-09-25)

The user explicitly changed the target workflow after reviewing the first implementation:

1. The public-gallery `+` action asks for **only the gallery name**, with the existing functional title autocomplete. Parent context may be carried as a hidden field. No description, SimBrief, tags, language, visibility, or advanced settings in this first step.
2. Creating the gallery immediately opens its editor in the same right-side panel. The editor owns all settings discussed earlier: description, SimBrief Pilot ID/name and description generation, source language, tags, API, Access, and other existing tabs.
3. Per-admin saved defaults must visibly appear pre-filled the next time they apply. Show a clear, quiet note beside a pre-filled value. The editor must include the controls to save Pilot ID, pilot name, and language as defaults for future galleries; saving the editor must persist selected defaults.
4. Every editor tab (including API and Access) must switch its visible content inside the open panel without changing the public page URL.

Implementation status on 2026-09-25: the name-only `+` panel, editor preference fields and saved-default labels, editor preference persistence, and panel-local tab switching are implemented in the current uncommitted worktree. The full audit passed (235 PHP tests, 24 Node tests, 36 WinApp tests, 5 Chromium browser tests); seven database-backed integrations were skipped because their disposable database fixture was not configured.

This section supersedes the older proposal and unfinished-work notes below. Keep it as the user's requested handoff for future continuation.

---

# Historical gallery creation implementation handoff

**Historical snapshot from the earlier interrupted implementation. The current direction and verification status are above.**

The intended design is in `TEMP_GALLERY_CREATION_UX_PROPOSAL.md`. Implementation was underway when the user asked to stop and continue in the desktop app. No commit was made for these implementation changes.

## Work completed so far

- Reordered the gallery editor tabs so API is immediately after Identity in `app/controllers/admin_galleries_edit_page/overview.php`.
- Moved tags into the main Identity card and placed the date, URL slug, disk folder, parent, sort order, and Smart Gallery controls into a collapsed Advanced gallery settings disclosure in `app/views/admin_gallery_edit_tabs.php`.
- Added basic disclosure and create-form styles in `public/assets/styles/side-panel.css`.
- Added a new create-form layout for the ordinary empty-gallery path in `app/views/admin_gallery_forms.php`: title, SimBrief controls, description, source language, and tags, followed by collapsed More options. The separate create-and-upload branch was left in its prior layout.
- Added per-admin preference model/service and migration: `app/models/gallery_creation_preferences.php`, `app/services/gallery_creation_preferences.php`, `database/migrations/202609250001_gallery_creation_preferences.php`. Registered the modules in `app/models.php` and `app/services.php`.
- Added a private temporary SimBrief draft service in `app/services/simbrief_description_drafts.php` and registered it in `app/services.php`. `app/controllers/admin_simbrief.php` now has an initial preview branch when `gallery_id` is zero.
- Added initial create-time language and tag persistence in `app/services/gallery_sidecars.php`, and draft attachment plus preference-saving orchestration in `app/controllers/admin_galleries_discovery.php`.
- Updated the SimBrief browser module to carry a draft reference and changed its cache-busting import in `public/assets/gallery.js`.
- Added several English translation keys in `app/lang/en.json`.

## Verification state

The first quick audit ran after the tab/layout changes and **failed** on two specific issues: missing English key `admin.gallery_editor.advanced_settings` and a missing `@return` annotation in `overview.php`. Both were edited afterward. That audit also reported WinApp coverage **BLOCKED** because Python was not found in PATH; its PHP and JavaScript syntax checks passed at that stage.

A second `php scripts/audit.php --profile=quick` was started after the larger changes, but the tool call was interrupted by the user before a result was returned. It may have continued as a background process. **No passing audit result exists for the current worktree.** Do not assume syntax, contracts, browser behavior, or migrations are correct.

## Important unfinished work and likely defects to inspect first

1. Check whether the interrupted audit is still running and inspect its compact summary if it completed. Do not start duplicate audits until this is known.
2. Fix any PHP syntax and documentation-contract failures in the newly added/modified files. The view changes and service registrations have not passed an audit.
3. Verify the new migration and preference model against the project's schema inspection and MariaDB conventions. The preference service currently calls `mb_strlen()` without a fallback; check whether mbstring is guaranteed or replace it safely.
4. Verify the SimBrief draft cache location, file permissions, access protection, TTL cleanup, and ownership checks. The draft service stores the full OFP temporarily and must remain private. It has not been tested.
5. The create controller currently attaches the draft after gallery creation and catches attachment failures as warnings. Check operation-key replay, partial outcomes, logging, and whether the mutation envelope communicates warnings to the side panel. It also saves remembered preferences after creation; validate requested preferences before any irreversible gallery creation.
6. The new create form and its POST path have not been browser-tested. Confirm that both panel and direct-page forms find the correct description textarea and that dynamic re-renders still intercept SimBrief import and creation. Confirm that edits to Pilot ID/name invalidate the old draft, while changing description text does not.
7. Add Czech translations and ensure `app/lang/en.json` remains valid and passes the translation catalog check. The new labels currently have only English entries.
8. Add relevant focused regression tests for preference isolation, create-time tags/language, draft ownership/expiry, and side-panel persistence. Register them through the normal test tree, then use the central audit as the authoritative runner.
9. Complete UI details from the proposal: preserve entered values on validation errors, open hidden advanced fields on validation errors, show accurate visibility/parent summary, offer a useful transition to the adjacent API tab, and verify narrow-screen appearance/accessibility.
10. After the final source edit, regenerate and check `app/core-manifest.json` as required by `AGENTS.md`, then run `php scripts/audit.php --profile=full` once before handoff. Do not claim completion before that passes or limitations are clearly reported.

## Files to review

`app/controllers/admin_galleries_edit_page/overview.php`, `app/views/admin_gallery_edit_tabs.php`, `app/views/admin_gallery_forms.php`, `app/controllers/admin_gallery_form_models.php`, `app/controllers/admin_galleries_discovery.php`, `app/controllers/admin_simbrief.php`, `app/services/gallery_sidecars.php`, `app/services/gallery_creation_preferences.php`, `app/services/simbrief_description_drafts.php`, `app/models/gallery_creation_preferences.php`, `database/migrations/202609250001_gallery_creation_preferences.php`, `app/models.php`, `app/services.php`, `public/assets/gallery-modules/admin-simbrief-description.js`, `public/assets/gallery.js`, `public/assets/styles/side-panel.css`, and `app/lang/en.json`.

The earlier documentation proposal remains committed as `e02e424`. These implementation edits are uncommitted.
