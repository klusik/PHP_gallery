# Proposal: faster creation of flight screenshot galleries

**Status:** temporary design proposal for review; no application behavior has been changed.

## Goal and intended result

Creating an FS24 flight gallery should feel like one short task: enter or accept the title, choose whether to import the latest SimBrief flight, review the description, add tags, and create the gallery. The existing controls for folder names, URLs, dates, parent placement, and other uncommon adjustments should remain available without occupying the main path. After creation, the editor should open in the same right-side panel, with the API tab immediately beside the main gallery tab.

The default path should require roughly: open `+` -> enter title -> optionally import SimBrief -> add tags -> create. A returning admin whose SimBrief identifiers and description language are remembered should not re-enter them. Import still requires an explicit click; merely opening the panel must not contact SimBrief or silently change the description.

## What the repository does today

| Area | Current behavior | Relevant code |
| --- | --- | --- |
| Create panel | The `+` action opens a create form in the Admin side panel. It includes title suggestion, folder name, visibility, dates, parent, description, voting, filename display, and a count badge. It does not include tags, source language, or SimBrief. | `app/views/admin_gallery_forms.php`; `app/controllers/admin_galleries_discovery.php` |
| Creation | The create endpoint normalizes a limited input set and calls `create_empty_gallery()`. On success its JSON mutation envelope asks the panel to show the new gallery editor; the classic redirect remains a fallback. | `app/controllers/admin_galleries_discovery.php`; `app/services/gallery_sidecars.php`; `public/assets/gallery-modules/admin-side-panel.js` |
| SimBrief | The editor has Pilot ID/name fields and a Generate button. The endpoint requires an existing gallery ID, fetches the latest OFP, returns a Markdown draft, and attempts to save OFP and route map against that gallery. Pilot ID wins if both fields contain values. | `app/views/admin_gallery_forms.php`; `app/controllers/admin_simbrief.php`; `public/assets/gallery-modules/admin-simbrief-description.js` |
| Description language | An optional localization control starts at `Not specified` because a new/current row has no `content_language`. The selection is presently in the editor, after creation. This is the language of the source title and description, distinct from the Admin UI or public site's default language. | `app/views/admin_gallery_forms.php`; `app/controllers/admin_gallery_form_models.php` |
| Tags and advanced fields | Tags are below the main description card, after URL slug, disk folder, parent, and sort order. They are not collected in the create panel. The editor has no progressive disclosure for those identity fields. | `app/views/admin_gallery_edit_tabs.php`; `app/controllers/admin_galleries_edit_page/tab_identity.php` |
| API | Tab order is Identity, Access, Display, Media, API, Images, then optional tools. The API tab includes upload keys, AI reprocessing, and migration. API-key mutations already have a side-panel AJAX handler. | `app/controllers/admin_galleries_edit_page/overview.php`; `app/controllers/admin_galleries_edit_page/tab_tools.php`; `public/assets/gallery-modules/admin-side-panel.js` |

This is chiefly a sequencing and information hierarchy problem. The create form is already in place, but the high-frequency flight tasks are split across it and the subsequent editor.

## Proposed interface

### 1. Make the create panel a short, task-oriented form

Suggested order in the panel:

1. **Gallery name** with the existing title suggestion. Preserve its current keyboard and screen-reader behavior.
2. **Flight details from SimBrief** (shown only when the SimBrief capability is effectively enabled): Pilot ID and pilot name prefilled from the current admin's saved defaults, each with its own `Remember for next galleries` checkbox. Keep the existing explanation that Pilot ID has priority when both are present. Show one clear `Get latest flight description` action.
3. **Description** directly below the import action. The fetched draft appears here for review and editing. Never overwrite typed text without an explicit replacement choice shown in the panel. Display import progress and errors beside the action. The user can still type a description manually.
4. **Description language**, visible when multilingual content is enabled and its schema is ready. Use the label `Language of this gallery's title and description`. Preselect the current admin's remembered language when one exists; otherwise use `Not specified`. Put `Remember this as my default` beside it. Do not confuse this with translation target language, Admin interface language, or site default language.
5. **Tags**, visible before advanced settings, using the existing suggestion and comma-separated input behavior.
6. **Create gallery** as the primary, persistent panel action. Keep its button visible on longer panels if the existing panel layout supports a sticky action area; otherwise ensure it is easy to reach without losing context.
7. **More options** as a collapsed `<details>` section: folder name, visibility, date range, parent if not already fixed by context, voting, filename display, count badge. Keep any security-relevant visibility value understandable near the submit button (for example `Creates as Unpublished`) even when the selector is collapsed. When a user opens the panel inside a parent gallery, show that parent in the compact summary.

The normal direct Admin page and the separate create-and-upload workflow should use the same prepared field groups where appropriate. Do not silently alter the upload workflow's submission semantics while improving the empty-gallery path.

### 2. Make one import click work before gallery creation

The current SimBrief endpoint cannot be reused unchanged in the create form: it rejects a missing gallery ID and writes the fetched OFP/route immediately. Split the operation into two stages behind the same visible import action:

- **Preview stage:** validate the identifiers, fetch and normalize the latest OFP, and return the editable description plus a bounded draft reference. Do not create a gallery, route row, or gallery file at this stage. The panel shows the draft and import status. A draft reference needs a short lifetime and must be tied to the authenticated admin/session; avoid putting the full OFP or credentials in browser storage or hidden fields.
- **Create stage:** submit title, edited description, selected language, tags, normal gallery fields, and the draft reference through the existing operation-key creation path. Create the gallery once, then attach the previously fetched OFP and derived route to the new ID. A changed or expired draft must produce a clear recoverable message instead of importing a different latest flight without warning.

This preserves the user's requested `click and it downloads a description` interaction while avoiding a slow, surprise network call during `Create gallery`. It also lets the user review and edit the generated description before creation. The import action remains optional. If SimBrief fails, keep the form and anything already typed intact; a manual gallery must remain creatable. If attaching OFP/route fails after gallery creation, report that partial outcome clearly and keep the new gallery accessible. Do not suggest that the whole create failed when the gallery exists.

An even shorter variation could combine import and create into one submit, but it would hide the draft review step and make network delays/failure handling harder. The preview-then-create version is the recommended first implementation.

### 3. Remember the right things at the right scope

- Store Pilot ID and pilot name as two **per-admin preferences**, not gallery fields and not a global preference. Each checkbox controls whether that exact field becomes the value offered next time. If unchecked, using a value for this gallery must not replace the remembered one. A separate clear action in account/preferences settings should be possible; removing the value and saving with its remember control enabled should clear that preference.
- Store the preferred source-description language per admin as another preference. A manually selected language should affect this gallery immediately. It should update the preference only when `Remember this as my default` is checked and creation succeeds. An explicit `Not specified` saved as default is valid and should clear any previous language preference.
- Show checkboxes unchecked by default until the admin opts in. Once a preference has been saved, show its value prefilled and explain that it is a personal default. Defaults must never overwrite an existing gallery's saved `content_language` during editing.
- Validate remembered language against `content_supported_languages()` when preparing the form. If the language is no longer supported, fall back visibly to `Not specified` without corrupting stored gallery data.
- Keep preferences server-side through the existing account/settings storage pattern, if suitable. Do not use shared browser `localStorage` for potentially personal SimBrief identifiers or for values that should follow the admin between devices. Confirm the project's existing per-user settings owner before adding storage; add a migration only if no suitable store exists.

### 4. Reorder the editor's Identity content

In the newly opened editor, put **title, description, source language, SimBrief, and tags** in the main content path. Place **URL slug, disk folder, parent, sort order, and Smart Gallery attachment controls** under a collapsed `Advanced gallery settings` section. Use a short summary for values that matter while collapsed, such as current URL slug and folder. Keep the title and description area visible without expanding anything. Keep advanced fields keyboard accessible and reopen the section automatically when a validation error concerns a hidden field.

This change should preserve all existing edit capabilities. Do not collapse critical feedback, error messages, or controls required to recover from a failure. Consider keeping parent placement visible in the create panel when the action was launched from a parent gallery, because accidentally creating in the wrong location is costly.

### 5. Put API immediately after Identity

Change the tab order to **Identity, API, Access, Display, Media, Images, optional tools**. Keep the stable `admin-edit-api` ID and existing routes/handlers; only the presentation order changes. For clarity, its heading may say `API & automation`, since that tab also includes AI reprocessing and migration. After successful creation, keep Identity active so the user can verify description/tags, but make API the next adjacent tab. If issuing a key immediately is a frequent next step, add a small `Next: API key` link in the create success state that switches the already-open panel to the API tab without changing the page URL or opening a standalone Admin page.

## Suggested implementation map

| Responsibility | Likely owner/change |
| --- | --- |
| Create form field groups and advanced disclosure | `app/views/admin_gallery_forms.php`, with data prepared by `app/controllers/admin_gallery_form_models.php` and `app/controllers/admin_galleries_discovery.php` |
| SimBrief fetch and draft validation | Existing `app/services/simbrief_descriptions.php` plus a focused service for short-lived draft handoff; controller `app/controllers/admin_simbrief.php` handles HTTP/CSRF/JSON only |
| Create-time metadata, localization, tags, and OFP/route attachment | Extend the existing create orchestration in `app/services/gallery_sidecars.php` or its appropriate service owner; use the existing tag and localization services, with model modules owning SQL |
| Preference persistence | Existing per-user setting/model owner after inspection; controller passes the current admin ID and semantic values, service validates/saves them |
| Panel behavior and dynamic fragments | `public/assets/gallery-modules/admin-side-panel.js` and `admin-simbrief-description.js`; delegated handlers, no full-page navigation, update the cache-busting import in `public/assets/gallery.js` |
| Identity layout and tab order | `app/views/admin_gallery_edit_tabs.php`, `app/controllers/admin_galleries_edit_page/tab_identity.php`, `app/controllers/admin_galleries_edit_page/overview.php` |
| CSS and strings | Existing Admin CSS in `public/assets/` and translation dictionaries; add English/Czech strings for new labels, help, progress, errors, and remembered-default state |

The create endpoint's existing `admin_mutation_success_envelope()` and operation-key behavior should remain the completion contract. Any new persistent side-panel action should follow the canonical mutation envelope and shared completion coordinator. Do not add another browser refresh/retry implementation.

## Edge cases and acceptance criteria

1. An admin can create a gallery entirely from the side panel; the public page URL does not change, the panel stays open, and the new editor appears there.
2. Reopening a dynamically rendered create panel still uses the import and create handlers without rebinding the page.
3. Pilot ID and pilot name are independently remembered per admin. Pilot ID priority remains clear when both are filled; changing one for a single gallery does not alter its saved default unless requested.
4. The language starts at `Not specified` for an admin with no saved preference, then preselects the remembered supported language on later creations. It is saved on the new gallery and does not change the site's or Admin's language.
5. Import produces a visible editable draft before creation. An import failure leaves the title, tags, language, and manually entered description untouched.
6. A stale draft, double click, interrupted request, or retry does not create duplicate galleries or silently attach a different OFP. The existing operation key remains authoritative for create replay.
7. Tags save during creation and appear when the editor opens; existing tag suggestions still work in both panel and direct-page contexts.
8. Advanced fields start collapsed, retain submitted values when validation fails, and open when a hidden field needs correction. The basic path shows its visibility and parent context.
9. API is the second tab on desktop and narrow screens. Key generation remains in the same panel and still reveals the newly generated raw key only through its existing one-time display behavior.
10. Disabling SimBrief or localization hides only the related controls. Manual gallery creation remains available. Schema `unknown` states keep the existing refusal policy for dependent writes.
11. Direct-page and no-JavaScript submission remain usable; the create form can simply create from manually entered fields when the preview import requires JavaScript.

## Verification when implementing

Add focused contracts for preference isolation, create-time language/tags, SimBrief draft ownership and expiry, one-time create replay, and partial attachment reporting. Add browser checks for panel persistence, unchanged URL, dynamic re-render interception, advanced disclosure, and API tab order. Follow the repository's central audit contract: use `php scripts/audit.php --profile=quick` while implementing, then `php scripts/audit.php --profile=full` once before code handoff. If updater-managed application files change, regenerate and check `app/core-manifest.json` after the final edit.

## Recommended delivery sequence

1. Rearrange Identity and API presentation, moving tags upward and putting uncommon identity controls in `Advanced gallery settings`. This gives an immediate usability improvement with no SimBrief workflow change.
2. Add create-time tags and source language, plus opt-in per-admin language preference.
3. Add SimBrief identifier preferences and the pre-create draft handoff; connect it to gallery creation and post-create OFP/route attachment.
4. Finish the create success affordance, translations, narrow-screen layout, and browser regression coverage.

The end state should keep all current features but make the common flight gallery workflow visible and linear.
